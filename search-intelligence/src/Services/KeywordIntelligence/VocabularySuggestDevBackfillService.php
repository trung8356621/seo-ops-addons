<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;

/**
 * Temporary dev utility: seo_article_keywords → Vocabulary Suggest (canonical KI ingest).
 * Safe to delete after local Keywords UI work.
 */
final class VocabularySuggestDevBackfillService
{
    /** @var list<string> Canonical group keys allowed for this backfill only. */
    public const BACKFILL_GROUPS = [
        VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS,
        VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL,
        VocabularyKeywordIngestionPolicy::GROUP_SEMANTIC,
        VocabularyKeywordIngestionPolicy::GROUP_RELEVANT_ENTITIES,
        VocabularyKeywordIngestionPolicy::GROUP_SEMANTIC_ENTITIES,
    ];

    public function __construct(
        private readonly VocabularyKeywordIntelligenceIngestionService $vocabularyKiIngestion,
        private readonly VocabularyKeywordIngestionPolicy $policy,
        private readonly SeoDatabaseConnectionService $seoDatabase,
    ) {}

    /**
     * @return array{
     *   articles: list<array{article_id: int, title: string, group_count: int}>,
     *   candidates: list<array{article_id: int, group: string, phrase: string, existing: bool}>,
     *   scanned: int,
     *   selected: int,
     *   would_ingest_new: int,
     *   would_dedupe: int,
     *   feedback: array<string, mixed>|null
     * }
     */
    public function run(int $siteId, int $articleLimit, int $suggestLimit, int $perArticleCap, bool $dryRun): array
    {
        if ($siteId <= 0) {
            throw new \InvalidArgumentException('site_id is required.');
        }

        $this->seoDatabase->bootstrapLegacySharedConnection();

        $articles = $this->resolveArticles($siteId, $articleLimit);
        $poolNew = [];
        $poolSuggestStamp = [];
        $seen = [];

        foreach ($articles as $article) {
            $groups = $this->loadKeywordGroups((int) $article->id);
            $picked = $this->pickCandidates($groups, max($perArticleCap * 8, 16));
            $articleTaken = 0;
            foreach ($picked as $row) {
                if ($articleTaken >= $perArticleCap * 2) {
                    break;
                }
                $phrase = Keyword::preparePhraseForStorage($row['phrase']);
                if ($phrase === '') {
                    continue;
                }
                $key = mb_strtolower($phrase);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $existing = Keyword::query()
                    ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$phrase])
                    ->first();

                if (! $existing instanceof Keyword) {
                    $poolNew[] = [
                        'article_id' => (int) $article->id,
                        'group' => $row['group'],
                        'phrase' => $phrase,
                        'existing' => false,
                        'status' => 'new',
                    ];
                    $articleTaken++;
                    continue;
                }

                // Canonical upsert does not promote TYPE_NORMAL → SUGGEST.
                // Re-stamp Suggest rows missing ai_generated so UI shows Vocabulary Suggest.
                $type = (string) ($existing->type ?? '');
                $source = mb_strtolower(trim((string) ($existing->source ?? '')));
                if ($type === Keyword::TYPE_SUGGEST && $source !== 'ai_generated') {
                    $poolSuggestStamp[] = [
                        'article_id' => (int) $article->id,
                        'group' => $row['group'],
                        'phrase' => $phrase,
                        'existing' => true,
                        'status' => 'suggest_restamp',
                    ];
                    $articleTaken++;
                }
            }
        }

        $candidates = array_slice($poolNew, 0, $suggestLimit);
        if (count($candidates) < $suggestLimit) {
            $need = $suggestLimit - count($candidates);
            $candidates = array_merge($candidates, array_slice($poolSuggestStamp, 0, $need));
        }

        $feedback = null;
        if (! $dryRun && $candidates !== []) {
            $feedback = $this->ingestSelected($articles, $candidates);
        }

        $newCount = count(array_filter($candidates, static fn (array $c): bool => ($c['status'] ?? '') === 'new'));
        $restampCount = count(array_filter($candidates, static fn (array $c): bool => ($c['status'] ?? '') === 'suggest_restamp'));
        $dupCount = $restampCount;

        return [
            'articles' => array_map(static function (SeoArticle $article): array {
                $raw = ArticleMeta::query()
                    ->where('article_id', (int) $article->id)
                    ->where('meta_key', 'seo_article_keywords')
                    ->value('meta_value');
                $decoded = is_string($raw) ? json_decode($raw, true) : null;

                return [
                    'article_id' => (int) $article->id,
                    'title' => mb_substr(trim((string) ($article->title ?? '')), 0, 80),
                    'group_count' => is_array($decoded) ? count($decoded) : 0,
                ];
            }, $articles),
            'candidates' => $candidates,
            'scanned' => count($candidates),
            'selected' => count($candidates),
            'would_ingest_new' => $newCount,
            'would_dedupe' => $dupCount,
            'would_restamp_suggest' => $restampCount,
            'feedback' => $feedback,
        ];
    }

    /**
     * @return list<SeoArticle>
     */
    private function resolveArticles(int $siteId, int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $ids = SeoArticle::query()
            ->where('site_id', $siteId)
            ->orderByDesc('id')
            ->limit(120)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $out = [];
        foreach ($ids as $id) {
            $groups = $this->loadKeywordGroups($id);
            if ($this->pickCandidates($groups, 1) === []) {
                continue;
            }
            $article = SeoArticle::query()->find($id);
            if (! $article instanceof SeoArticle) {
                continue;
            }
            $out[] = $article;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function loadKeywordGroups(int $articleId): array
    {
        $raw = ArticleMeta::query()
            ->where('article_id', $articleId)
            ->where('meta_key', 'seo_article_keywords')
            ->value('meta_value');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $groups = [];
        foreach ($decoded as $name => $items) {
            if (! is_array($items)) {
                continue;
            }
            $list = [];
            foreach ($items as $item) {
                $phrase = trim(is_string($item) ? $item : (string) ($item['keyword'] ?? $item['phrase'] ?? $item['title'] ?? ''));
                if ($phrase !== '') {
                    $list[] = $phrase;
                }
            }
            if ($list !== []) {
                $groups[(string) $name] = array_values(array_unique($list));
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @return list<array{group: string, phrase: string}>
     */
    private function pickCandidates(array $groups, int $cap): array
    {
        if ($cap <= 0 || $groups === []) {
            return [];
        }

        $byCanonical = [];
        foreach ($groups as $heading => $phrases) {
            $canonical = $this->policy->resolveCanonicalGroup((string) $heading);
            if ($canonical === null || ! in_array($canonical, self::BACKFILL_GROUPS, true)) {
                continue;
            }
            foreach ($phrases as $phrase) {
                $prepared = Keyword::preparePhraseForStorage((string) $phrase);
                if ($prepared === '' || $this->wordCount($prepared) < 2) {
                    continue;
                }
                $byCanonical[$canonical][] = $prepared;
            }
        }

        $picked = [];
        $seen = [];
        // Round-robin across priority groups for quality mix.
        $priority = self::BACKFILL_GROUPS;
        $indexes = array_fill_keys($priority, 0);
        while (count($picked) < $cap) {
            $added = false;
            foreach ($priority as $canonical) {
                $list = $byCanonical[$canonical] ?? [];
                $i = $indexes[$canonical];
                while ($i < count($list)) {
                    $phrase = $list[$i];
                    $i++;
                    $indexes[$canonical] = $i;
                    $key = mb_strtolower($phrase);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $picked[] = ['group' => $canonical, 'phrase' => $phrase];
                    $added = true;
                    break;
                }
                if (count($picked) >= $cap) {
                    break;
                }
            }
            if (! $added) {
                break;
            }
        }

        return $picked;
    }

    /**
     * @param  list<SeoArticle>  $articles
     * @param  list<array{article_id: int, group: string, phrase: string, existing: bool}>  $candidates
     * @return array<string, mixed>
     */
    private function ingestSelected(array $articles, array $candidates): array
    {
        $byArticle = [];
        foreach ($candidates as $row) {
            $aid = (int) $row['article_id'];
            $byArticle[$aid][$row['group']][] = $row['phrase'];
        }

        $articlesById = [];
        foreach ($articles as $article) {
            $articlesById[(int) $article->id] = $article;
        }

        $aggregate = [
            'discovered' => 0,
            'ingested' => 0,
            'classified' => 0,
            'filtered' => 0,
            'duplicates' => 0,
            'source_preserved' => 0,
            'groups' => [],
            'errors' => [],
        ];

        foreach ($byArticle as $articleId => $groups) {
            $article = $articlesById[$articleId] ?? SeoArticle::query()->find($articleId);
            if (! $article instanceof SeoArticle) {
                $aggregate['errors'][] = 'article_missing:'.$articleId;
                continue;
            }

            // Rebuild heading labels the policy understands.
            $payload = [];
            foreach ($groups as $canonical => $phrases) {
                $payload[$this->headingForCanonical((string) $canonical)] = array_values(array_unique($phrases));
            }

            $feedback = $this->vocabularyKiIngestion->ingestFromVocabularyGroups(
                $article,
                $payload,
                [
                    'origin' => 'dev_vocabulary_suggest_backfill',
                    'workflow_node_id' => 'dev:vocabulary-suggest-backfill',
                ],
            );

            foreach (['discovered', 'ingested', 'classified', 'filtered', 'duplicates', 'source_preserved'] as $key) {
                $aggregate[$key] += (int) ($feedback[$key] ?? 0);
            }
            foreach (is_array($feedback['groups'] ?? null) ? $feedback['groups'] : [] as $g => $n) {
                $aggregate['groups'][$g] = (int) ($aggregate['groups'][$g] ?? 0) + (int) $n;
            }
            foreach (is_array($feedback['errors'] ?? null) ? $feedback['errors'] : [] as $err) {
                $aggregate['errors'][] = (string) $err;
            }
        }

        return $aggregate;
    }

    private function headingForCanonical(string $canonical): string
    {
        return match ($canonical) {
            VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS => 'Related topics',
            VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL => 'Long-tail keywords',
            VocabularyKeywordIngestionPolicy::GROUP_SEMANTIC => 'Semantic keywords',
            VocabularyKeywordIngestionPolicy::GROUP_RELEVANT_ENTITIES => 'Relevant entities',
            VocabularyKeywordIngestionPolicy::GROUP_SEMANTIC_ENTITIES => 'Semantic entities',
            default => $canonical,
        };
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
