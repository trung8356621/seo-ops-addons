<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordAiCandidateGuard;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;

/**
 * Vocabulary Suggest ingestion — reads typed article_vocabulary / keyword_groups only.
 */
final class VocabularyKeywordIntelligenceIngestionService
{
    public function __construct(
        private readonly VocabularyKeywordIngestionPolicy $policy,
        private readonly KeywordSourceNormalizer $sources,
        private readonly KeywordPersistenceService $persistence,
        private readonly KeywordMetaRepository $metaRepository,
        private readonly KeywordAiCandidateGuard $guard,
    ) {}

    /**
     * @param  array<string, list<string>|mixed>  $keywordGroups
     * @param  array<string, mixed>  $provenance
     * @return array<string, mixed>
     */
    public function ingestFromVocabularyGroups(SeoArticle $article, array $keywordGroups, array $provenance = []): array
    {
        $siteId = (int) $article->site_id;
        if ($siteId <= 0) {
            return $this->emptyFeedback(['invalid_site']);
        }

        $existing = $this->existingSiteKeywords($siteId);
        $feedback = $this->emptyFeedback();
        $feedback['groups'] = [];

        foreach ($keywordGroups as $groupName => $items) {
            if (! is_array($items)) {
                continue;
            }

            $canonical = $this->policy->resolveCanonicalGroup((string) $groupName);
            if ($canonical === null || ! $this->policy->isEnabled($canonical)) {
                continue;
            }

            $groupStats = [
                'group' => $canonical,
                'discovered' => 0,
                'ingested' => 0,
                'duplicates' => 0,
                'filtered' => 0,
            ];

            foreach ($items as $rawPhrase) {
                $phrase = trim((string) $rawPhrase);
                if ($phrase === '') {
                    continue;
                }

                $groupStats['discovered']++;
                $feedback['discovered']++;

                $evaluated = $this->guard->evaluate([$phrase], $existing, KeywordSourceNormalizer::AI_GENERATED, $siteId);
                $decision = $evaluated[0]['decision'] ?? 'reject';
                if ($decision !== 'accept') {
                    $groupStats['filtered']++;
                    $feedback['filtered']++;

                    continue;
                }

                $feedback['classified']++;

                $saved = $this->persistence->upsert(
                    $phrase,
                    Keyword::TYPE_SUGGEST,
                    $siteId,
                    metrics: [
                        'vocabulary_group' => $canonical,
                        'article_vocabulary' => true,
                        'article_id' => (int) $article->id,
                    ],
                );

                if (! $saved instanceof Keyword) {
                    $groupStats['filtered']++;
                    $feedback['filtered']++;

                    continue;
                }

                $this->stampKeywordSource($saved, $siteId, $canonical, $article, $provenance);

                $existing[] = [
                    'normalized_text' => (string) ($evaluated[0]['normalized_text'] ?? mb_strtolower($phrase)),
                    'folded_text' => (string) ($evaluated[0]['folded_text'] ?? mb_strtolower($phrase)),
                ];

                $groupStats['ingested']++;
                $feedback['ingested']++;
            }

            if ($groupStats['discovered'] > 0) {
                $feedback['groups'][] = $groupStats;
            }
        }

        return $feedback;
    }

    /**
     * @param  array<string, mixed>  $provenance
     */
    private function stampKeywordSource(
      Keyword $keyword,
      int $siteId,
      string $canonicalGroup,
      SeoArticle $article,
      array $provenance,
    ): void {
        if (! $keyword->source_locked) {
            $keyword->forceFill(['source' => KeywordSourceNormalizer::AI_GENERATED])->save();
        }

        $metaKey = 'site.'.$siteId.'.'.$this->policy->evidenceMetaSuffix($canonicalGroup);
        $payload = [[
            'article_id' => (int) $article->id,
            'vocabulary_group' => $canonicalGroup,
            'prompt_result_id' => (int) ($provenance['prompt_result_id'] ?? 0) ?: null,
            'origin' => 'Vocabulary Suggest',
        ]];

        $this->metaRepository->set((int) $keyword->id, $metaKey, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<array{normalized_text: string, folded_text: string}>
     */
    private function existingSiteKeywords(int $siteId): array
    {
        $rows = Keyword::query()
            ->forSite($siteId)
            ->limit(500)
            ->get(['id', 'phrase']);

        $existing = [];
        foreach ($rows as $keyword) {
            if (! $keyword instanceof Keyword) {
                continue;
            }
            $phrase = mb_strtolower(Keyword::decodePhrase((string) $keyword->phrase));
            if ($phrase === '') {
                continue;
            }
            $existing[] = [
                'normalized_text' => $phrase,
                'folded_text' => $phrase,
            ];
        }

        return $existing;
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function emptyFeedback(array $errors = []): array
    {
        return [
            'discovered' => 0,
            'ingested' => 0,
            'classified' => 0,
            'filtered' => 0,
            'duplicates' => 0,
            'source_preserved' => 0,
            'groups' => [],
            'errors' => $errors,
        ];
    }
}