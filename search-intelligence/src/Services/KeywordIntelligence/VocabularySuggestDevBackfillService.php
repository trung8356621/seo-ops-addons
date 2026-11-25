<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleKeywordDistinctCounter;

/**
 * Dev utility: article_meta.seo_article_keywords → Vocabulary Suggest (strong groups only).
 */
final class VocabularySuggestDevBackfillService
{
    /** @var list<string> */
    public const BACKFILL_GROUPS = [
        VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS,
        VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL,
    ];

    public function __construct(
        private readonly VocabularyKeywordIntelligenceIngestionService $ingestion,
    ) {}

    /**
     * @return array{
     *   articles: list<array{article_id: int, title: string, group_count: int}>,
     *   selected: int,
     *   would_ingest_new: int,
     *   would_restamp_suggest: int,
     *   would_dedupe: int,
     *   candidates: list<array<string, mixed>>,
     *   feedback: array<string, mixed>|null
     * }
     */
    public function run(int $siteId, int $articleLimit, int $suggestLimit, int $perArticle, bool $dryRun): array
    {
        $articles = $this->loadArticles($siteId, $articleLimit);
        $candidates = [];
        $selected = 0;

        foreach ($articles as $articleRow) {
            $articleId = (int) ($articleRow['article_id'] ?? 0);
            $groups = $articleRow['groups'] ?? [];
            if ($articleId <= 0 || ! is_array($groups) || $groups === []) {
                continue;
            }

            foreach (self::BACKFILL_GROUPS as $canonical) {
                $heading = $this->headingForCanonical($canonical);
                $items = $groups[$heading] ?? [];
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $phrase) {
                    $phrase = trim((string) $phrase);
                    if ($phrase === '') {
                        continue;
                    }
                    $candidates[] = [
                        'article_id' => $articleId,
                        'group' => $canonical,
                        'phrase' => $phrase,
                        'status' => 'new',
                    ];
                    $selected++;
                    if ($selected >= $suggestLimit) {
                        break 3;
                    }
                }
            }
        }

        $feedback = null;
        if (! $dryRun && $candidates !== []) {
            $feedback = ['discovered' => 0, 'ingested' => 0, 'duplicates' => 0, 'filtered' => 0, 'classified' => 0, 'errors' => []];
            foreach ($articles as $articleRow) {
                $article = SeoArticle::query()->find((int) ($articleRow['article_id'] ?? 0));
                if (! $article instanceof SeoArticle) {
                    continue;
                }
                $subset = $this->filterGroupsForBackfill($articleRow['groups'] ?? []);
                if ($subset === []) {
                    continue;
                }
                $rowFeedback = $this->ingestion->ingestFromVocabularyGroups($article, $subset, ['origin' => 'dev_backfill']);
                foreach (['discovered', 'ingested', 'duplicates', 'filtered', 'classified'] as $key) {
                    $feedback[$key] = (int) ($feedback[$key] ?? 0) + (int) ($rowFeedback[$key] ?? 0);
                }
                if (is_array($rowFeedback['errors'] ?? null)) {
                    $feedback['errors'] = [...$feedback['errors'], ...$rowFeedback['errors']];
                }
            }
        }

        return [
            'articles' => array_map(static fn (array $row): array => [
                'article_id' => (int) ($row['article_id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'group_count' => is_array($row['groups'] ?? null) ? count($row['groups']) : 0,
            ], $articles),
            'selected' => $selected,
            'would_ingest_new' => $dryRun ? $selected : (int) ($feedback['ingested'] ?? 0),
            'would_restamp_suggest' => 0,
            'would_dedupe' => (int) ($feedback['duplicates'] ?? 0),
            'candidates' => $candidates,
            'feedback' => $feedback,
        ];
    }

    /**
     * @return list<array{article_id: int, title: string, groups: array<string, list<string>>}>
     */
    private function loadArticles(int $siteId, int $limit): array
    {
        $rows = DB::connection('omi_seo_ai')->table('article_meta as am')
            ->join('articles as a', 'a.id', '=', 'am.article_id')
            ->where('am.meta_key', ArticleKeywordDistinctCounter::META_KEY)
            ->when($siteId > 0, static fn ($query) => $query->where('a.site_id', $siteId))
            ->orderByDesc('am.id')
            ->limit(max(1, $limit))
            ->get(['a.id as article_id', 'a.title', 'am.meta_value']);

        $articles = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row->meta_value ?? ''), true);
            if (! is_array($decoded)) {
                continue;
            }
            $articles[] = [
                'article_id' => (int) ($row->article_id ?? 0),
                'title' => (string) ($row->title ?? ''),
                'groups' => $this->normalizeGroups($decoded),
            ];
        }

        return $articles;
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, list<string>>
     */
    private function normalizeGroups(array $raw): array
    {
        $groups = [];
        foreach ($raw as $group => $items) {
            if (! is_array($items)) {
                continue;
            }
            $list = [];
            foreach ($items as $item) {
                $phrase = is_string($item) || is_numeric($item) ? trim((string) $item) : '';
                if ($phrase !== '') {
                    $list[] = $phrase;
                }
            }
            if ($list !== []) {
                $groups[(string) $group] = $list;
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @return array<string, list<string>>
     */
    private function filterGroupsForBackfill(array $groups): array
    {
        $out = [];
        foreach (self::BACKFILL_GROUPS as $canonical) {
            $heading = $this->headingForCanonical($canonical);
            if (! isset($groups[$heading])) {
                continue;
            }
            $out[$heading] = $groups[$heading];
        }

        return $out;
    }

    private function headingForCanonical(string $canonical): string
    {
        return match ($canonical) {
            VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS => 'Related topics',
            VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL => 'Long-tail keywords',
            default => $canonical,
        };
    }
}