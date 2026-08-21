<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use App\Core\Operations\LongRunningProgress;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchIntelligence\Jobs\ClassifyDirtyKeywordsJob;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterSiteScope;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordClassification;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordDictionaryBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordLandscapeBuilder;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordRuleClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\Seo\Jobs\AuditLinkStatusJob;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpEligibleContentScope;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

final class KeywordClassificationService
{
    public const META_PROGRESS = 'seo_keyword_intelligence_progress';

    public const META_LANDSCAPE = 'seo_keyword_landscape';

    public const META_DIRTY = 'seo_keyword_intelligence_dirty';

    public function __construct(
        private readonly KeywordNormalizer $normalizer,
        private readonly KeywordRuleClassifier $classifier,
        private readonly KeywordDictionaryBuilder $dictionary,
        private readonly KeywordSourceNormalizer $sources,
        private readonly KeywordCanonicalizer $canonicalizer,
        private readonly KeywordLandscapeBuilder $landscape,
    ) {}

    /**
     * @return array{processed: int, skipped: int, dirty_remaining: int, metrics: array<string, int>}
     */
    public function classifyBatch(int $siteId, int $limit, bool $dryRun, bool $force): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
            return ['processed' => 0, 'skipped' => 0, 'dirty_remaining' => 0, 'metrics' => []];
        }

        $started = now()->toIso8601String();
        $query = $this->keywordQuery($siteId)->withCount('linkMaps');
        if (! $force && Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'is_dirty')) {
            $cleanIds = SeoKeywordClassification::query()
                ->where('is_dirty', false)
                ->whereNotNull('input_hash')
                ->pluck('keyword_id');
            if ($cleanIds->isNotEmpty()) {
                $query->whereNotIn('id', $cleanIds);
            }
        }
        $total = (clone $query)->count();
        $processed = 0;
        $skipped = 0;
        $metrics = [
            'keyword_phrase' => 0,
            'query' => 0,
            'sentence' => 0,
            'descriptive_phrase' => 0,
            'brand_entity' => 0,
            'url_domain' => 0,
            'noise' => 0,
            'ambiguous' => 0,
        ];

        $this->writeProgress($siteId, LongRunningProgress::fromArray([
            'status' => 'running',
            'phase' => 'classify',
            'step' => 1,
            'total_steps' => 3,
            'current' => 0,
            'total' => min($limit, $total),
            'started_at' => $started,
            'last_activity_at' => $started,
            'metrics' => $metrics,
        ]));

        foreach ($query->limit($limit)->get() as $keyword) {
            if (! $keyword instanceof Keyword) {
                continue;
            }
            $raw = (string) $keyword->phrase;
            $norm = $this->normalizer->normalize($raw);
            $inputHash = hash('sha256', $norm['normalized_text'].'|'.(string) ($keyword->source ?? ''));
            $existing = SeoKeywordClassification::query()->find((int) $keyword->id);
            if ($existing instanceof SeoKeywordClassification && ! $force) {
                $existingHash = (string) ($existing->input_hash ?? '');
                $dirty = (bool) ($existing->is_dirty ?? false);
                if ($existingHash === $inputHash && ! $dirty) {
                    $skipped++;
                    continue;
                }
            }

            $sourceKind = $this->sources->normalize(is_string($keyword->source ?? null) ? (string) $keyword->source : null);
            $classified = $this->classifier->classify($raw, $norm['normalized_text'], [
                'source_kind' => $sourceKind,
                'occurrence_count' => max(1, (int) ($keyword->link_maps_count ?? 1)),
            ]);
            $processed++;
            $metrics[$classified['phrase_kind']] = ($metrics[$classified['phrase_kind']] ?? 0) + 1;
            if ($classified['is_ambiguous']) {
                $metrics['ambiguous']++;
            }
            if ($dryRun) {
                continue;
            }

            $payload = [
                'raw_text' => $norm['raw_text'],
                'normalized_text' => $norm['normalized_text'],
                'folded_text' => $norm['folded_text'],
                'phrase_kind' => $classified['phrase_kind'],
                'seo_intent' => $classified['seo_intent'],
                'canonical_keyword_id' => (int) $keyword->id,
                'is_anchor_candidate' => $classified['is_anchor_candidate'],
                'anchor_priority' => $classified['anchor_priority'],
                'classification_confidence' => $classified['classification_confidence'],
                'classified_at' => now(),
                'is_dirty' => false,
                'input_hash' => $inputHash,
                'classification_hash' => hash('sha256', $classified['phrase_kind'].'|'.$classified['seo_intent'].'|'.$norm['folded_text']),
            ];

            $existingClusterKey = $existing instanceof SeoKeywordClassification
                ? trim((string) ($existing->cluster_key ?? ''))
                : '';
            if ($existingClusterKey !== '') {
                $payload['cluster_key'] = $existingClusterKey;
            } else {
                $payload['cluster_key'] = null;
            }
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'source_kind')) {
                $payload['source_kind'] = $sourceKind;
                $payload['is_seo_keyword'] = $classified['is_seo_keyword'];
                $payload['is_ambiguous'] = $classified['is_ambiguous'];
                $payload['keyword_score'] = $classified['keyword_score'];
                $payload['segments'] = $classified['segments'];
            }

            SeoKeywordClassification::query()->updateOrCreate(
                ['keyword_id' => (int) $keyword->id],
                $payload,
            );
            app(KeywordGroupMembershipService::class)->syncKeyword((int) $keyword->id, $raw);

            if ($processed % 20 === 0) {
                $this->writeProgress($siteId, LongRunningProgress::fromArray([
                    'status' => 'running',
                    'phase' => 'classify',
                    'step' => 1,
                    'total_steps' => 3,
                    'current' => $processed,
                    'total' => min($limit, $total),
                    'started_at' => $started,
                    'last_activity_at' => now()->toIso8601String(),
                    'metrics' => $metrics,
                ]));
            }
        }

        if (! $dryRun) {
            $this->assignCanonicals($siteId);
            $this->refreshLandscapeSnapshot($siteId);
        }

        $dirtyRemaining = $this->countDirty($siteId);
        $this->writeProgress($siteId, LongRunningProgress::fromArray([
            'status' => $dirtyRemaining > 0 ? 'running' : 'completed',
            'phase' => 'classify',
            'step' => 3,
            'total_steps' => 3,
            'current' => $processed,
            'total' => min($limit, $total),
            'started_at' => $started,
            'last_activity_at' => now()->toIso8601String(),
            'finished_at' => $dirtyRemaining > 0 ? null : now()->toIso8601String(),
            'metrics' => $metrics,
        ]));

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'dirty_remaining' => $dirtyRemaining,
            'metrics' => $metrics,
        ];
    }

    public function readProgress(int $siteId): ?LongRunningProgress
    {
        if ($siteId <= 0) {
            return null;
        }
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return null;
        }
        $raw = SiteSyncSiteMeta::getJson($site, self::META_PROGRESS);
        if (! is_array($raw)) {
            return null;
        }

        return LongRunningProgress::fromArray($raw);
    }

    public function markSiteDirty(int $siteId): void
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return;
        }
        SiteSyncSiteMeta::putJson($site, self::META_DIRTY, [
            'dirty' => true,
            'marked_at' => now()->toIso8601String(),
        ]);
    }

    public function dispatchClassifyJob(int $siteId): void
    {
        ClassifyDirtyKeywordsJob::dispatch($siteId)->onQueue(AuditLinkStatusJob::QUEUE_NAME);
    }

    /**
     * @return array{version: string, hash: string, clusters: list<array<string, mixed>>}
     */
    public function buildDictionary(int $siteId): array
    {
        $rows = $this->classificationRows($siteId);

        return $this->dictionary->build($rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function landscape(int $siteId): array
    {
        $site = Site::query()->find($siteId);
        if ($site instanceof Site) {
            $cached = SiteSyncSiteMeta::getJson($site, self::META_LANDSCAPE);
            if (is_array($cached) && isset($cached['clusters'])) {
                return $cached;
            }
        }

        return $this->refreshLandscapeSnapshot($siteId);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshLandscapeSnapshot(int $siteId): array
    {
        $rows = $this->classificationRows($siteId);
        $built = $this->landscape->build($rows, $this->coverageByCluster($siteId, $rows));
        $site = Site::query()->find($siteId);
        $progress = $site instanceof Site ? (SiteSyncSiteMeta::getJson($site, self::META_PROGRESS) ?? []) : [];
        $status = (string) ($progress['status'] ?? 'idle');
        $built['generated_at'] = now()->toIso8601String();
        $built['classification_freshness'] = $progress['last_activity_at'] ?? null;
        $built['stale'] = in_array($status, ['queued', 'running', 'stale'], true);
        $built['analysis_status'] = $status !== '' ? $status : 'idle';
        if ($site instanceof Site) {
            SiteSyncSiteMeta::putJson($site, self::META_LANDSCAPE, $built);
            SiteSyncSiteMeta::putJson($site, self::META_DIRTY, [
                'dirty' => false,
                'cleared_at' => now()->toIso8601String(),
            ]);
        }

        return $built;
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(int $siteId): array
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return ['status' => 'idle'];
        }

        return SiteSyncSiteMeta::getJson($site, self::META_PROGRESS) ?? ['status' => 'idle'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function classificationRows(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
            return [];
        }
        $query = SeoKeywordClassification::query();
        if ($siteId > 0) {
            $ids = $this->keywordQuery($siteId)->pluck('id');
            $query->whereIn('keyword_id', $ids);
        }

        $canon = $this->canonicalizer;

        return $query->get()->map(static fn (SeoKeywordClassification $row): array => [
            'phrase_kind' => $row->phrase_kind,
            'normalized_text' => $row->normalized_text,
            'raw_text' => $row->raw_text,
            'folded_text' => $row->folded_text,
            'cluster_key' => $row->cluster_key,
            'is_anchor_candidate' => $row->is_anchor_candidate,
            'is_seo_keyword' => (bool) ($row->is_seo_keyword ?? in_array((string) $row->phrase_kind, ['keyword_phrase', 'query', 'brand_entity'], true)),
            'is_ambiguous' => (bool) ($row->is_ambiguous ?? false),
            'canonical_text' => $canon->pickDisplay([[
                'raw_text' => (string) ($row->raw_text ?? ''),
                'normalized_text' => (string) ($row->normalized_text ?? ''),
                'folded_text' => (string) ($row->folded_text ?? ''),
            ]]) ?: (string) $row->normalized_text,
            'canonical_keyword_id' => $row->canonical_keyword_id,
            'seo_intent' => $row->seo_intent,
            'keyword_id' => $row->keyword_id,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{target_pages: int, published: int, planned: int}>
     */
    private function coverageByCluster(int $siteId, array $rows): array
    {
        $byKeyword = [];
        foreach ($rows as $row) {
            $kid = (int) ($row['keyword_id'] ?? 0);
            $ck = (string) ($row['cluster_key'] ?? '');
            if ($kid > 0 && $ck !== '') {
                $byKeyword[$kid] = $ck;
            }
        }
        if ($byKeyword === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            return [];
        }

        $maps = SeoLinkMap::query()
            ->whereIn('keyword_id', array_keys($byKeyword))
            ->whereNotNull('target_article_id')
            // Only count eligible SEO content entities as cluster targets
            // (exclude local WP `type=page`).
            ->whereHas('targetArticle', static function ($targetQuery): void {
                $targetQuery = McpEligibleContentScope::applyToSeoArticleTarget($targetQuery);
            })
            ->get(['keyword_id', 'target_article_id', 'source_article_id']);

        $bucket = [];
        foreach ($maps as $map) {
            $ck = $byKeyword[(int) $map->keyword_id] ?? '';
            if ($ck === '') {
                continue;
            }
            $bucket[$ck]['targets'][(int) $map->target_article_id] = true;
        }

        $coverage = [];
        foreach ($bucket as $ck => $sets) {
            $pages = count($sets['targets'] ?? []);
            $coverage[$ck] = [
                'target_pages' => $pages,
                'published' => $pages,
                'planned' => 0,
            ];
        }
        unset($siteId);

        return $coverage;
    }

    private function assignCanonicals(int $siteId): void
    {
        $rows = SeoKeywordClassification::query()
            ->whereIn('phrase_kind', ['keyword_phrase', 'query', 'brand_entity'])
            ->when($siteId > 0, function ($q) use ($siteId): void {
                $ids = $this->keywordQuery($siteId)->pluck('id');
                $q->whereIn('keyword_id', $ids);
            })
            ->get();

        $byFold = [];
        foreach ($rows as $row) {
            $key = $this->canonicalizer->exactKey((string) $row->folded_text);
            if ($key === '') {
                continue;
            }
            $byFold[$key][] = $row;
        }

        foreach ($byFold as $members) {
            usort(
                $members,
                static fn (SeoKeywordClassification $a, SeoKeywordClassification $b): int => mb_strlen((string) $a->normalized_text) <=> mb_strlen((string) $b->normalized_text),
            );
            $canonicalId = (int) $members[0]->keyword_id;
            foreach ($members as $member) {
                $member->canonical_keyword_id = $canonicalId;
                if (Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'duplicate_of') && (int) $member->keyword_id !== $canonicalId) {
                    $member->duplicate_of = $canonicalId;
                }
                $member->save();
            }
        }
    }

    private function countDirty(int $siteId): int
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_keyword_classifications', 'is_dirty')) {
            return 0;
        }
        $ids = $this->keywordQuery($siteId)->pluck('id');
        $classified = SeoKeywordClassification::query()->whereIn('keyword_id', $ids)->count();
        $dirty = SeoKeywordClassification::query()->whereIn('keyword_id', $ids)->where('is_dirty', true)->count();
        $missing = max(0, $ids->count() - $classified);

        return $dirty + $missing;
    }

    private function keywordQuery(int $siteId)
    {
        $query = Keyword::query()->orderBy('id');
        if ($siteId <= 0) {
            return $query;
        }

        return KeywordClusterSiteScope::apply($query, $siteId);
    }

    private function writeProgress(int $siteId, LongRunningProgress $progress): void
    {
        if ($siteId <= 0) {
            return;
        }
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return;
        }
        SiteSyncSiteMeta::putJson($site, self::META_PROGRESS, $progress->toArray());
    }
}
