<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscIntelligencePolicy;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscPlanningSignalNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Throwable;

/**
 * GSC query → Keyword Intelligence identity (deterministic, 0 AI).
 * Does NOT overwrite focus_keyword / main keyword / seo_link_maps.
 * Does NOT invent search volume or KD from impressions.
 */
final class GscKeywordIntelligenceIngestionService
{
    /**
     * @var array<string, int>
     */
    private const SOURCE_RANK = [
        KeywordSourceNormalizer::MANUAL => 100,
        KeywordSourceNormalizer::SITE_SYNC => 90,
        KeywordSourceNormalizer::SEARCH_CONSOLE => 80,
        KeywordSourceNormalizer::KEYWORD_DISCOVERY => 70,
        KeywordSourceNormalizer::CONTENT_PROJECT => 60,
        KeywordSourceNormalizer::IMPORT => 50,
        KeywordSourceNormalizer::AI_GENERATED => 40,
        KeywordSourceNormalizer::OTHER => 10,
        KeywordSourceNormalizer::ANCHOR_TEXT => 30,
    ];

    public function __construct(
        private readonly KeywordPersistenceService $keywordPersistence,
        private readonly KeywordMetaRepository $metaRepository,
        private readonly KeywordClassificationService $classification,
        private readonly KeywordSourceNormalizer $sourceNormalizer,
        private readonly CtaKeywordBlacklistFilter $ctaFilter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $mappings  from GSC sync (normalized_query, keyword_mapping?, …)
     * @return array{
     *   discovered: int,
     *   ingested: int,
     *   classified: int,
     *   filtered: int,
     *   duplicates: int,
     *   source_preserved: int,
     *   errors: list<string>
     * }
     */
    public function ingestFromSyncMappings(int $siteId, array $mappings, array $provenance = []): array
    {
        $summary = [
            'discovered' => 0,
            'ingested' => 0,
            'classified' => 0,
            'filtered' => 0,
            'duplicates' => 0,
            'source_preserved' => 0,
            'errors' => [],
        ];

        if ($siteId <= 0) {
            $summary['errors'][] = 'site_missing';

            return $summary;
        }

        $seen = [];
        $cap = GscIntelligencePolicy::MAX_KI_INGEST_PER_SYNC;

        foreach ($mappings as $mapping) {
            if (count($seen) >= $cap) {
                break;
            }
            if (! is_array($mapping)) {
                continue;
            }

            $phrase = trim((string) ($mapping['normalized_query'] ?? $mapping['query'] ?? ''));
            if ($phrase === '' || isset($seen[$phrase])) {
                continue;
            }
            $seen[$phrase] = true;
            $summary['discovered']++;

            try {
                $result = $this->ingestPhrase($siteId, $phrase, $provenance);
            } catch (Throwable $e) {
                $summary['errors'][] = mb_substr($e->getMessage(), 0, 160);
                $summary['filtered']++;

                continue;
            }

            match ($result['status']) {
                'ingested' => $summary['ingested']++,
                'duplicate' => $summary['duplicates']++,
                default => $summary['filtered']++,
            };
            if ($result['classified']) {
                $summary['classified']++;
            }
            if ($result['source_preserved']) {
                $summary['source_preserved']++;
            }
        }

        if ($summary['ingested'] > 0 || $summary['classified'] > 0) {
            $this->classification->markSiteDirty($siteId);
        }

        return $summary;
    }

    /**
     * Safe wrapper — never throws to caller (sync must succeed even if KI fails).
     *
     * @param  list<array<string, mixed>>  $mappings
     * @return array<string, mixed>
     */
    public function ingestFromSyncMappingsSafe(int $siteId, array $mappings, array $provenance = []): array
    {
        try {
            return $this->ingestFromSyncMappings($siteId, $mappings, $provenance);
        } catch (Throwable $e) {
            return [
                'discovered' => 0,
                'ingested' => 0,
                'classified' => 0,
                'filtered' => 0,
                'duplicates' => 0,
                'source_preserved' => 0,
                'errors' => [mb_substr($e->getMessage(), 0, 160)],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $provenance
     * @return array{status: string, classified: bool, source_preserved: bool, keyword_id: int|null}
     */
    private function ingestPhrase(int $siteId, string $phrase, array $provenance): array
    {
        $prepared = Keyword::preparePhraseForStorage($phrase);
        if ($prepared === '' || $this->wordCount($prepared) < 2) {
            return ['status' => 'filtered', 'classified' => false, 'source_preserved' => false, 'keyword_id' => null];
        }

        try {
            if ($this->ctaFilter->isBlocked($prepared)) {
                return ['status' => 'filtered', 'classified' => false, 'source_preserved' => false, 'keyword_id' => null];
            }
        } catch (Throwable) {
            // Fail open when CTA settings unavailable.
        }

        $existingBefore = Keyword::query()
            ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$prepared])
            ->first();
        $existedForSite = $existingBefore instanceof Keyword
            && $this->metaRepository->keywordHasSiteMeta((int) $existingBefore->id, $siteId);

        $keyword = $this->keywordPersistence->upsert(
            $prepared,
            Keyword::TYPE_SUGGEST,
            $siteId,
            null,
            null,
            [
                'origin' => 'gsc_search_performance',
                'evidence_type' => GscPlanningSignalNormalizer::EVIDENCE_TYPE,
                Keyword::METRIC_RESCRAPE_KEEP => true,
            ],
        );
        if (! $keyword instanceof Keyword) {
            return ['status' => 'filtered', 'classified' => false, 'source_preserved' => false, 'keyword_id' => null];
        }

        // Explicit: never write article.focus_keyword / main keyword from GSC.
        $sourcePreserved = $this->applySearchConsoleSource($keyword);
        $this->appendEvidence($keyword, $siteId, $provenance);
        $classified = $this->classification->classifyOne($keyword, $siteId);

        return [
            'status' => $existedForSite ? 'duplicate' : 'ingested',
            'classified' => $classified,
            'source_preserved' => $sourcePreserved,
            'keyword_id' => (int) $keyword->id,
        ];
    }

    private function applySearchConsoleSource(Keyword $keyword): bool
    {
        $desired = KeywordSourceNormalizer::SEARCH_CONSOLE;
        if ((bool) ($keyword->source_locked ?? false)) {
            return true;
        }

        $currentRaw = is_string($keyword->source ?? null) ? (string) $keyword->source : '';
        $current = $this->sourceNormalizer->normalize($currentRaw !== '' ? $currentRaw : null);
        $currentRank = self::SOURCE_RANK[$current] ?? 0;
        $desiredRank = self::SOURCE_RANK[$desired] ?? 0;

        if ($currentRaw !== '' && $currentRank > $desiredRank) {
            return true;
        }
        if ($current === $desired) {
            return false;
        }

        $keyword->forceFill(['source' => $desired])->save();

        return false;
    }

    /**
     * @param  array<string, mixed>  $provenance
     */
    private function appendEvidence(Keyword $keyword, int $siteId, array $provenance): void
    {
        $metaKey = "site.{$siteId}.gsc_evidence.observed_query";
        $existingRaw = $this->metaRepository->get((int) $keyword->id, $metaKey);
        $list = [];
        if (is_string($existingRaw) && $existingRaw !== '') {
            $decoded = json_decode($existingRaw, true);
            if (is_array($decoded)) {
                $list = $decoded;
            }
        }

        $entry = array_filter([
            'evidence_type' => GscPlanningSignalNormalizer::EVIDENCE_TYPE,
            'origin' => 'gsc_search_performance',
            'property_ref' => isset($provenance['property_ref']) ? (string) $provenance['property_ref'] : null,
            'sync_run_ref' => isset($provenance['sync_run_ref']) ? (string) $provenance['sync_run_ref'] : null,
            'period' => isset($provenance['period']) ? (string) $provenance['period'] : null,
            'at' => now()->toIso8601String(),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        array_unshift($list, $entry);
        $list = array_slice($list, 0, 10);
        $this->metaRepository->set((int) $keyword->id, $metaKey, json_encode($list, JSON_UNESCAPED_UNICODE));
    }

    private function wordCount(string $phrase): int
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];

        return count(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
