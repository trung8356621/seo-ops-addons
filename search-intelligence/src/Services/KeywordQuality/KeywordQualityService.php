<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordQuality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityCode;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualitySchema;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use RuntimeException;

final class KeywordQualityService
{
    public function __construct(
        private readonly KeywordQualityClassifier $classifier = new KeywordQualityClassifier,
    ) {}

    public function scanSite(int $siteId, bool $onlyStale = false): void
    {
        if ($siteId <= 0 || ! KeywordQualitySchema::tablesReady()) {
            return;
        }

        $phrases = [];
        Keyword::query()
            ->forSite($siteId)
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$phrases): void {
                foreach ($chunk as $keyword) {
                    if (! $keyword instanceof Keyword) {
                        continue;
                    }
                    $phrases[(int) $keyword->id] = (string) $keyword->phrase;
                }
            });

        $this->scanPhrases($siteId, $phrases, $onlyStale);
    }

    /**
     * @param  array<int, string>  $phrasesById
     */
    public function scanPhrases(int $siteId, array $phrasesById, bool $onlyStale = false): void
    {
        if ($siteId <= 0 || ! KeywordQualitySchema::tablesReady()) {
            return;
        }

        $total = count($phrasesById);
        $this->markScan($siteId, 'running', $total, 0, null);
        $normalized = [];
        foreach ($phrasesById as $id => $phrase) {
            $normalized[(int) $id] = $this->classifier->normalized((string) $phrase);
        }
        $duplicateSet = array_fill_keys($this->classifier->duplicateKeywordIds($normalized), true);
        $truncatedSet = array_fill_keys($this->classifier->truncatedCopyIds($normalized), true);
        $containedSet = array_fill_keys($this->classifier->containedCopyIds($phrasesById), true);
        $ctaSet = array_fill_keys($this->transactionalDestinationIds($siteId), true);
        $processed = 0;

        try {
            foreach (array_chunk($phrasesById, 200, true) as $chunk) {
                $ids = [];
                foreach (array_keys($chunk) as $id) {
                    $ids[] = (int) $id;
                }
                $staleIds = $onlyStale ? $this->staleIds($siteId, $ids) : $ids;
                $existing = $this->tagOrigins($siteId, $staleIds);
                $suppressed = $this->suppressedCodes($siteId, $staleIds);

                DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $chunk, $staleIds, $duplicateSet, $truncatedSet, $containedSet, $ctaSet, $existing, $suppressed): void {
                    $now = now();
                    foreach ($staleIds as $keywordId) {
                        $phrase = (string) ($chunk[$keywordId] ?? '');
                        $detected = $this->classifier->classify(
                            $phrase,
                            isset($duplicateSet[$keywordId]),
                            isset($truncatedSet[$keywordId]),
                            isset($containedSet[$keywordId]),
                            isset($ctaSet[$keywordId]),
                        );
                        $blocked = $suppressed[$keywordId] ?? [];
                        $current = $existing[$keywordId] ?? [];
                        $manual = [];
                        foreach ($current as $code => $origin) {
                            if ($origin === 'manual') {
                                $manual[$code] = true;
                            }
                        }
                        $keepAuto = [];
                        foreach ($detected as $code) {
                            if (isset($blocked[$code]) || isset($manual[$code])) {
                                continue;
                            }
                            $keepAuto[$code] = true;
                        }
                        foreach ($current as $code => $origin) {
                            if (! KeywordQualityCode::isKnown($code) || ($origin === 'auto' && ! isset($keepAuto[$code]))) {
                                $this->deleteTag($siteId, $keywordId, $code);
                            }
                        }
                        foreach (array_keys($keepAuto) as $code) {
                            if (isset($current[$code])) {
                                continue;
                            }
                            $this->insertTag($siteId, $keywordId, $code, 'auto', $now);
                        }
                        $this->touchSeen($siteId, $keywordId, $now);
                    }
                });

                $processed += count($chunk);
                $this->markScan($siteId, 'running', $total, $processed, null);
            }
            $this->markScan($siteId, 'done', $total, $processed, null);
        } catch (\Throwable $e) {
            $this->markScan($siteId, 'failed', $total, $processed, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return list<int>
     */
    private function transactionalDestinationIds(int $siteId): array
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_link_maps') || ! $schema->hasTable('articles')) {
            return [];
        }

        $select = ['m.keyword_id'];
        foreach (['target_external_url', 'link_type', 'destination_kind'] as $column) {
            if ($schema->hasColumn('seo_link_maps', $column)) {
                $select[] = 'm.'.$column;
            }
        }

        $links = DB::connection('omi_seo_ai')->table('seo_link_maps as m')
            ->join('articles as a', 'a.id', '=', 'm.source_article_id')
            ->where('a.site_id', $siteId)
            ->whereNotNull('m.keyword_id')
            ->get($select);

        return $this->classifier->transactionalDestinationKeywordIds($links);
    }

    /**
     * @param  list<string>  $codes
     */
    public function syncManual(int $siteId, int $keywordId, array $codes): void
    {
        $this->assertKeywordOnSite($siteId, $keywordId);
        $wanted = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if (KeywordQualityCode::isKnown($code)) {
                $wanted[$code] = true;
            }
        }

        $now = now();
        DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $keywordId, $wanted, $now): void {
            DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags')
                ->where('site_id', $siteId)
                ->where('keyword_id', $keywordId)
                ->delete();
            DB::connection('omi_seo_ai')->table('seo_keyword_quality_suppressions')
                ->where('site_id', $siteId)
                ->where('keyword_id', $keywordId)
                ->delete();
            foreach (array_keys($wanted) as $code) {
                $this->insertTag($siteId, $keywordId, $code, 'manual', $now);
            }
            foreach (KeywordQualityCode::all() as $code) {
                if (isset($wanted[$code])) {
                    continue;
                }
                DB::connection('omi_seo_ai')->table('seo_keyword_quality_suppressions')->insert([
                    'site_id' => $siteId,
                    'keyword_id' => $keywordId,
                    'code' => $code,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->touchSeen($siteId, $keywordId, $now);
        });
    }

    public function restoreAutomatic(int $siteId, int $keywordId): void
    {
        $this->assertKeywordOnSite($siteId, $keywordId);
        $phrase = (string) Keyword::query()->whereKey($keywordId)->value('phrase');
        DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $keywordId): void {
            foreach (['seo_keyword_quality_tags', 'seo_keyword_quality_suppressions', 'seo_keyword_quality_seen'] as $table) {
                DB::connection('omi_seo_ai')->table($table)
                    ->where('site_id', $siteId)
                    ->where('keyword_id', $keywordId)
                    ->delete();
            }
        });
        $this->scanPhrases($siteId, [$keywordId => $phrase], false);
    }

    /**
     * @return list<string>
     */
    public function codesFor(int $siteId, int $keywordId): array
    {
        if ($siteId <= 0 || $keywordId <= 0 || ! KeywordQualitySchema::tablesReady()) {
            return [];
        }

        return DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags')
            ->where('site_id', $siteId)
            ->where('keyword_id', $keywordId)
            ->orderBy('code')
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->all();
    }

    public function isExcluded(int $siteId, string $code): bool
    {
        if ($siteId <= 0 || ! KeywordQualityCode::isKnown($code) || ! KeywordQualitySchema::tablesReady()) {
            return false;
        }

        return DB::connection('omi_seo_ai')->table('seo_keyword_quality_policies')
            ->where('site_id', $siteId)
            ->where('code', $code)
            ->where('exclude_from_topic', 1)
            ->exists();
    }

    public function setExcluded(int $siteId, string $code, bool $exclude): void
    {
        if ($siteId <= 0 || ! KeywordQualityCode::isKnown($code) || ! KeywordQualitySchema::tablesReady()) {
            return;
        }

        $now = now();
        DB::connection('omi_seo_ai')->table('seo_keyword_quality_policies')->updateOrInsert(
            ['site_id' => $siteId, 'code' => $code],
            ['exclude_from_topic' => $exclude ? 1 : 0, 'updated_at' => $now, 'created_at' => $now],
        );
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  list<string>|null  $languageVariants
     */
    /**
     * @param  list<string>|null  $languageVariants
     * @param  array<string, mixed>  $filters
     */
    public function summary(int $siteId, ?array $languageVariants = null, array $filters = []): array
    {
        $emptyTags = [];
        foreach (KeywordQualityCode::all() as $code) {
            $emptyTags[] = [
                'code' => $code,
                'label' => KeywordQualityCode::label($code),
                'count' => 0,
                'percent' => 0.0,
            ];
        }
        $empty = [
            'total' => 0,
            'unique_tagged' => 0,
            'unique_tagged_percent' => 0.0,
            'unique_excluded' => 0,
            'unique_excluded_percent' => 0.0,
            'scanned' => 0,
            'tags' => $emptyTags,
            'scan_status' => null,
            'scan_processed' => 0,
            'scan_total' => 0,
            'scan_error' => null,
        ];
        if ($siteId <= 0 || ! KeywordQualitySchema::tablesReady()) {
            return $empty;
        }

        $filters['quality_codes'] = [];
        $filters['quality_scope'] = null;
        $population = app(KeywordDictionaryQuery::class)->filteredForSummary($siteId, $languageVariants, $filters);
        $total = (int) (clone $population)->count();
        $populationIds = (clone $population)->select('keywords.id');
        $tagRows = DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags')
            ->where('site_id', $siteId)
            ->whereIn('keyword_id', clone $populationIds)
            ->selectRaw('code, COUNT(DISTINCT keyword_id) as keyword_count')
            ->groupBy('code')
            ->get();
        $counts = [];
        foreach ($tagRows as $row) {
            $counts[(string) $row->code] = (int) $row->keyword_count;
        }
        $uniqueTagged = $this->distinctKeywordCount($siteId, false, $populationIds);
        $uniqueExcluded = $this->distinctKeywordCount($siteId, true, $populationIds);
        $scanned = (int) DB::connection('omi_seo_ai')->table('seo_keyword_quality_seen')
            ->where('site_id', $siteId)
            ->count();
        $scan = DB::connection('omi_seo_ai')->table('seo_keyword_quality_scans')->where('site_id', $siteId)->first();

        $tags = [];
        foreach (KeywordQualityCode::all() as $code) {
            $count = $counts[$code] ?? 0;
            $tags[] = [
                'code' => $code,
                'label' => KeywordQualityCode::label($code),
                'count' => $count,
                'percent' => $this->percent($count, $total),
            ];
        }

        return [
            'total' => $total,
            'unique_tagged' => $uniqueTagged,
            'unique_tagged_percent' => $this->percent($uniqueTagged, $total),
            'unique_excluded' => $uniqueExcluded,
            'unique_excluded_percent' => $this->percent($uniqueExcluded, $total),
            'scanned' => $scanned,
            'tags' => $tags,
            'scan_status' => is_object($scan) ? (string) $scan->status : null,
            'scan_processed' => is_object($scan) ? (int) $scan->processed : 0,
            'scan_total' => is_object($scan) ? (int) $scan->total : 0,
            'scan_error' => is_object($scan) && $scan->last_error !== null ? (string) $scan->last_error : null,
        ];
    }

    private function distinctKeywordCount(int $siteId, bool $excludedOnly, mixed $populationIds): int
    {
        $query = DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags as kq_tags')
            ->where('kq_tags.site_id', $siteId)
            ->whereIn('kq_tags.keyword_id', clone $populationIds);
        if ($excludedOnly) {
            $query->join('seo_keyword_quality_policies as kq_policies', function ($join): void {
                $join->on('kq_policies.site_id', '=', 'kq_tags.site_id')
                    ->on('kq_policies.code', '=', 'kq_tags.code');
            })->where('kq_policies.exclude_from_topic', 1);
        }

        $row = $query->selectRaw('COUNT(DISTINCT kq_tags.keyword_id) as aggregate')->first();

        return (int) ($row->aggregate ?? 0);
    }

    private function percent(int $count, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($count / $total) * 100, 1);
    }

    private function assertKeywordOnSite(int $siteId, int $keywordId): void
    {
        if ($siteId <= 0 || $keywordId <= 0 || ! KeywordQualitySchema::tablesReady()) {
            throw new RuntimeException('keyword_quality_unavailable');
        }

        $onSite = Keyword::query()->forSite($siteId)->whereKey($keywordId)->exists();
        if (! $onSite) {
            throw new RuntimeException('keyword_not_on_site');
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function staleIds(int $siteId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $fresh = DB::connection('omi_seo_ai')->table('seo_keyword_quality_seen')
            ->where('site_id', $siteId)
            ->whereIn('keyword_id', $ids)
            ->where('classifier_version', '>=', KeywordQualityCode::CLASSIFIER_VERSION)
            ->pluck('keyword_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $freshSet = array_fill_keys($fresh, true);

        return array_values(array_filter($ids, static fn (int $id): bool => ! isset($freshSet[$id])));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, string>>
     */
    private function tagOrigins(int $siteId, array $ids): array
    {
        return $this->codesForIds($siteId, 'seo_keyword_quality_tags', $ids, true);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, string>>
     */
    private function suppressedCodes(int $siteId, array $ids): array
    {
        return $this->codesForIds($siteId, 'seo_keyword_quality_suppressions', $ids, false);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, string>>
     */
    private function codesForIds(int $siteId, string $table, array $ids, bool $withOrigin): array
    {
        if ($ids === []) {
            return [];
        }

        $columns = $withOrigin ? ['keyword_id', 'code', 'origin'] : ['keyword_id', 'code'];
        $rows = DB::connection('omi_seo_ai')->table($table)
            ->where('site_id', $siteId)
            ->whereIn('keyword_id', $ids)
            ->get($columns);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->keyword_id][(string) $row->code] = $withOrigin ? (string) $row->origin : 'suppressed';
        }

        return $out;
    }

    private function deleteTag(int $siteId, int $keywordId, string $code): void
    {
        DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags')
            ->where('site_id', $siteId)
            ->where('keyword_id', $keywordId)
            ->where('code', $code)
            ->delete();
    }

    private function insertTag(int $siteId, int $keywordId, string $code, string $origin, mixed $now): void
    {
        DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags')->insert([
            'site_id' => $siteId,
            'keyword_id' => $keywordId,
            'code' => $code,
            'origin' => $origin,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function touchSeen(int $siteId, int $keywordId, mixed $now): void
    {
        DB::connection('omi_seo_ai')->table('seo_keyword_quality_seen')->updateOrInsert(
            ['site_id' => $siteId, 'keyword_id' => $keywordId],
            [
                'classifier_version' => KeywordQualityCode::CLASSIFIER_VERSION,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function noteScanQueued(int $siteId): void
    {
        if ($siteId <= 0 || ! KeywordQualitySchema::tablesReady()) {
            return;
        }

        $this->markScan($siteId, 'queued', 0, 0, null);
    }

    private function markScan(int $siteId, string $status, int $total, int $processed, ?string $error): void
    {
        $now = now();
        DB::connection('omi_seo_ai')->table('seo_keyword_quality_scans')->updateOrInsert(
            ['site_id' => $siteId],
            [
                'status' => $status,
                'total' => $total,
                'processed' => $processed,
                'last_error' => $error !== null ? mb_substr($error, 0, 255) : null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }
}
