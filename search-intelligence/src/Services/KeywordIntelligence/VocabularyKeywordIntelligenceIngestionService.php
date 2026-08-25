<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Throwable;

/**
 * Vocabulary → Keyword Intelligence feedback (deterministic, 0 AI calls).
 * Does NOT create seo_link_maps / focus coverage relations.
 * Does NOT write Draft items or MCP.
 */
final class VocabularyKeywordIntelligenceIngestionService
{
    private const EVIDENCE_CAP = 10;

    /**
     * Stronger sources must not be overwritten by AI vocabulary discovery.
     *
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
        private readonly VocabularyKeywordIngestionPolicy $policy,
        private readonly KeywordPersistenceService $keywordPersistence,
        private readonly KeywordMetaRepository $metaRepository,
        private readonly KeywordClassificationService $classification,
        private readonly KeywordSourceNormalizer $sourceNormalizer,
        private readonly CtaKeywordBlacklistFilter $ctaFilter,
    ) {}

    /**
     * @param  array<string, list<string>|mixed>  $keywordGroups
     * @param  array{
     *   prompt_result_id?: int|null,
     *   project_id?: int|null,
     *   project_task_id?: int|null,
     *   workflow_node_id?: string|null
     * }  $provenance
     * @return array{
     *   discovered: int,
     *   ingested: int,
     *   classified: int,
     *   filtered: int,
     *   duplicates: int,
     *   source_preserved: int,
     *   groups: array<string, int>,
     *   errors: list<string>
     * }
     */
    public function ingestFromVocabularyGroups(SeoArticle $article, array $keywordGroups, array $provenance = []): array
    {
        $summary = [
            'discovered' => 0,
            'ingested' => 0,
            'classified' => 0,
            'filtered' => 0,
            'duplicates' => 0,
            'source_preserved' => 0,
            'groups' => [],
            'errors' => [],
        ];

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            $summary['errors'][] = 'article_site_missing';

            return $summary;
        }

        foreach ($keywordGroups as $groupName => $phrases) {
            $canonical = $this->policy->resolveCanonicalGroup((string) $groupName);
            if ($canonical === null || ! $this->policy->isEnabled($canonical)) {
                continue;
            }
            if (! is_array($phrases)) {
                continue;
            }

            $groupIngested = 0;
            foreach ($phrases as $rawPhrase) {
                $summary['discovered']++;
                try {
                    $result = $this->ingestPhrase(
                        article: $article,
                        siteId: $siteId,
                        phrase: (string) $rawPhrase,
                        canonicalGroup: $canonical,
                        provenance: $provenance,
                    );
                } catch (Throwable $e) {
                    // Recoverable per-phrase: keep going; caller surfaces aggregate warning.
                    $summary['errors'][] = mb_substr($e->getMessage(), 0, 160);
                    $summary['filtered']++;

                    continue;
                }

                match ($result['status']) {
                    'ingested' => $summary['ingested']++,
                    'duplicate' => $summary['duplicates']++,
                    'filtered' => $summary['filtered']++,
                    default => $summary['filtered']++,
                };
                if ($result['classified']) {
                    $summary['classified']++;
                }
                if ($result['source_preserved']) {
                    $summary['source_preserved']++;
                }
                if ($result['status'] === 'ingested' || $result['status'] === 'duplicate') {
                    $groupIngested++;
                }
            }
            $summary['groups'][$canonical] = ($summary['groups'][$canonical] ?? 0) + $groupIngested;
        }

        if ($summary['ingested'] > 0 || $summary['classified'] > 0) {
            $this->classification->markSiteDirty($siteId);
        }

        return $summary;
    }

    /**
     * @param  list<string>  $phrases
     * @param  array<string, mixed>  $provenance
     * @return array{
     *   discovered: int,
     *   ingested: int,
     *   classified: int,
     *   filtered: int,
     *   duplicates: int,
     *   source_preserved: int,
     *   groups: array<string, int>,
     *   errors: list<string>
     * }
     */
    public function ingestRelatedTopics(SeoArticle $article, array $phrases, array $provenance = []): array
    {
        return $this->ingestFromVocabularyGroups(
            $article,
            ['Related topics' => $phrases],
            $provenance,
        );
    }

    /**
     * @param  array<string, mixed>  $provenance
     * @return array{status: string, classified: bool, source_preserved: bool, keyword_id: int|null}
     */
    private function ingestPhrase(
        SeoArticle $article,
        int $siteId,
        string $phrase,
        string $canonicalGroup,
        array $provenance,
    ): array {
        $prepared = Keyword::preparePhraseForStorage($phrase);
        if ($prepared === '' || $this->wordCount($prepared) < 2) {
            return ['status' => 'filtered', 'classified' => false, 'source_preserved' => false, 'keyword_id' => null];
        }
        if ($this->samePhrase($prepared, (string) ($article->title ?? ''))) {
            return ['status' => 'filtered', 'classified' => false, 'source_preserved' => false, 'keyword_id' => null];
        }
        try {
            if ($this->ctaFilter->isBlocked($prepared)) {
                return ['status' => 'filtered', 'classified' => false, 'source_preserved' => false, 'keyword_id' => null];
            }
        } catch (Throwable) {
            // Settings/DB unavailable in some test runtimes — do not block KI ingestion.
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
                'origin' => 'article_vocabulary',
                'vocabulary_group' => $canonicalGroup,
                Keyword::METRIC_RESCRAPE_KEEP => true,
            ],
        );
        if (! $keyword instanceof Keyword) {
            return ['status' => 'filtered', 'classified' => false, 'source_preserved' => false, 'keyword_id' => null];
        }

        $sourcePreserved = $this->applyAiGeneratedSource($keyword);
        $this->appendEvidence($keyword, $siteId, $canonicalGroup, $article, $provenance);

        // Never create coverage link maps / main_article_id from vocabulary discovery.
        $classified = $this->classification->classifyOne($keyword, $siteId);

        return [
            'status' => $existedForSite ? 'duplicate' : 'ingested',
            'classified' => $classified,
            'source_preserved' => $sourcePreserved,
            'keyword_id' => (int) $keyword->id,
        ];
    }

    /**
     * @return bool true when existing stronger source was preserved
     */
    private function applyAiGeneratedSource(Keyword $keyword): bool
    {
        $desired = KeywordSourceNormalizer::AI_GENERATED;
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
    private function appendEvidence(
        Keyword $keyword,
        int $siteId,
        string $canonicalGroup,
        SeoArticle $article,
        array $provenance,
    ): void {
        $suffix = $this->policy->evidenceMetaSuffix($canonicalGroup);
        $metaKey = "site.{$siteId}.{$suffix}";
        $existingRaw = $this->metaRepository->get((int) $keyword->id, $metaKey);

        $list = [];
        if (is_string($existingRaw) && $existingRaw !== '') {
            $decoded = json_decode($existingRaw, true);
            if (is_array($decoded)) {
                $list = $decoded;
            }
        }

        $entry = array_filter([
            'article_id' => (int) $article->getKey(),
            'vocabulary_group' => $canonicalGroup,
            'origin' => 'article_vocabulary',
            'prompt_result_id' => isset($provenance['prompt_result_id']) ? (int) $provenance['prompt_result_id'] : null,
            'project_id' => isset($provenance['project_id']) ? (int) $provenance['project_id'] : null,
            'project_task_id' => isset($provenance['project_task_id']) ? (int) $provenance['project_task_id'] : null,
            'workflow_node_id' => isset($provenance['workflow_node_id']) ? (string) $provenance['workflow_node_id'] : null,
            'at' => now()->toIso8601String(),
        ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== 0);

        // Dedup same article+group evidence; keep latest first.
        $list = array_values(array_filter(
            $list,
            static fn (mixed $row): bool => ! (
                is_array($row)
                && (int) ($row['article_id'] ?? 0) === (int) $article->getKey()
                && (string) ($row['vocabulary_group'] ?? '') === $canonicalGroup
            ),
        ));
        array_unshift($list, $entry);
        $list = array_slice($list, 0, self::EVIDENCE_CAP);

        $this->metaRepository->set(
            (int) $keyword->id,
            $metaKey,
            json_encode($list, JSON_UNESCAPED_UNICODE),
        );

        // Ensure site ownership for forSite() without coverage links.
        if (! $this->metaRepository->keywordHasSiteMeta((int) $keyword->id, $siteId)) {
            $this->metaRepository->setSiteString(
                (int) $keyword->id,
                $siteId,
                'vocab_bound',
                '1',
            );
        }
    }

    private function samePhrase(string $left, string $right): bool
    {
        $left = mb_strtolower(trim(Keyword::decodePhrase($left)));
        $right = mb_strtolower(trim(Keyword::decodePhrase($right)));

        return $left !== '' && $right !== '' && $left === $right;
    }

    private function wordCount(string $phrase): int
    {
        $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));
        if ($phrase === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $phrase) ?: []);
    }
}
