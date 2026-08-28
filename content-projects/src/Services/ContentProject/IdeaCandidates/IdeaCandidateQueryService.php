<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIngestionPolicy;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\VocabularySuggestStagingQuery;

/**
 * Read path: staging Vocabulary Suggest → Idea Candidate projection for Project Planner.
 * No AI. No Ideas table.
 */
final class IdeaCandidateQueryService
{
    public const PER_PAGE_DEFAULT = 20;

    /**
     * @var array<string, string>
     */
    private const GROUP_LABELS = [
        VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS => 'Related topics',
        VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL => 'Long-tail keywords',
        VocabularyKeywordIngestionPolicy::GROUP_SEMANTIC => 'Semantic keywords',
        VocabularyKeywordIngestionPolicy::GROUP_SEMANTIC_ENTITIES => 'Semantic entities',
        VocabularyKeywordIngestionPolicy::GROUP_RELEVANT_ENTITIES => 'Relevant entities',
        VocabularyKeywordIngestionPolicy::GROUP_HOLONYMY => 'Holonymy',
        VocabularyKeywordIngestionPolicy::GROUP_RELATIONAL_ENTITIES => 'Relational entities',
        VocabularyKeywordIngestionPolicy::GROUP_CLOSE_ENTITIES => 'Close entities',
        VocabularyKeywordIngestionPolicy::GROUP_SALIENT_ENTITIES => 'Salient entities',
        VocabularyKeywordIngestionPolicy::GROUP_SALIENT_KEYWORDS => 'Salient keywords',
    ];

    public function __construct(
        private readonly IdeaCandidateSourceCatalog $sources = new IdeaCandidateSourceCatalog,
    ) {}

    /**
     * @return list<array{key: string, label: string}>
     */
    public function sourceOptions(): array
    {
        return $this->sources->options();
    }

    /**
     * @param  array{
     *   source?: string|null,
     *   search?: string|null,
     *   exclude_draft_duplicates?: bool
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        int $siteId,
        ?SeoProject $draft = null,
        array $filters = [],
        int $page = 1,
        int $perPage = self::PER_PAGE_DEFAULT,
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        if ($siteId <= 0) {
            return new Paginator([], 0, $perPage, $page);
        }

        $sourceKey = trim((string) ($filters['source'] ?? IdeaCandidateSource::KEY_VOCABULARY_SUGGEST));
        if ($sourceKey === '' || $sourceKey === 'all') {
            $sourceKey = IdeaCandidateSource::KEY_VOCABULARY_SUGGEST;
        }

        // Phase 1: only Vocabulary Suggest is implemented.
        if ($sourceKey !== IdeaCandidateSource::KEY_VOCABULARY_SUGGEST) {
            return new Paginator([], 0, $perPage, $page);
        }

        $source = $this->sources->find(IdeaCandidateSource::KEY_VOCABULARY_SUGGEST);
        $sourceLabel = $source?->label
            ?? (string) __('seo-content-ai::filament.keyword.keyword_item_tag_vocabulary_suggest');

        $query = $this->baseVocabularyQuery($siteId);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $this->applyPhraseSearch($query, $search);
        }

        $excludeDraft = (bool) ($filters['exclude_draft_duplicates'] ?? true);
        if ($excludeDraft && $draft instanceof SeoProject && $draft->isDraftPlanning()) {
            $this->excludePlannedCreateDuplicates($query, $draft);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->forPage($page, $perPage)
            ->get(['id', 'phrase', 'type', 'source', 'review_status']);

        $keywordIds = $rows->map(static fn (Keyword $k): int => (int) $k->id)->all();
        $evidenceByKeyword = $this->loadEvidenceMap($keywordIds, $siteId);
        $articleTitles = $this->loadArticleTitles(
            array_values(array_unique(array_filter(array_map(
                static fn (array $e): int => (int) ($e['article_id'] ?? 0),
                $evidenceByKeyword,
            )))),
            $siteId,
        );

        $items = [];
        foreach ($rows as $keyword) {
            $kid = (int) $keyword->id;
            $phrase = Keyword::decodePhrase((string) ($keyword->phrase ?? ''));
            if ($phrase === '') {
                continue;
            }

            $evidence = $evidenceByKeyword[$kid] ?? null;
            $articleId = is_array($evidence) ? ((int) ($evidence['article_id'] ?? 0) ?: null) : null;
            $group = is_array($evidence) ? trim((string) ($evidence['vocabulary_group'] ?? '')) : '';
            $group = $group !== '' ? $group : null;

            $items[] = (new IdeaCandidate(
                candidateRef: IdeaCandidate::ref(IdeaCandidateSource::KEY_VOCABULARY_SUGGEST, $kid),
                keywordId: $kid,
                phrase: $phrase,
                source: IdeaCandidateSource::KEY_VOCABULARY_SUGGEST,
                sourceLabel: $sourceLabel,
                sourceArticleId: $articleId,
                sourceArticleTitle: $articleId !== null ? ($articleTitles[$articleId] ?? null) : null,
                vocabularyGroup: $group,
                vocabularyGroupLabel: $group !== null ? ($this->groupLabel($group)) : null,
                hint: $articleId !== null
                    ? (string) __('seo-content-ai::filament.projects.idea_candidate_hint_related_article')
                    : (string) __('seo-content-ai::filament.projects.idea_candidate_hint_no_focus'),
            ))->toArray();
        }

        $paginator = new Paginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'ideaCandidatesPage',
        ]);
        $paginator->setPageName('ideaCandidatesPage');

        return $paginator;
    }

    /**
     * @param  list<int>  $keywordIds
     * @return list<IdeaCandidate>
     */
    public function resolveVocabularyCandidates(int $siteId, array $keywordIds): array
    {
        $keywordIds = array_values(array_unique(array_filter(array_map('intval', $keywordIds))));
        if ($siteId <= 0 || $keywordIds === []) {
            return [];
        }

        $source = $this->sources->find(IdeaCandidateSource::KEY_VOCABULARY_SUGGEST);
        $sourceLabel = $source?->label
            ?? (string) __('seo-content-ai::filament.keyword.keyword_item_tag_vocabulary_suggest');

        $rows = $this->baseVocabularyQuery($siteId)
            ->whereIn('id', $keywordIds)
            ->get(['id', 'phrase', 'type', 'source']);

        $evidenceByKeyword = $this->loadEvidenceMap(
            $rows->map(static fn (Keyword $k): int => (int) $k->id)->all(),
            $siteId,
        );

        $out = [];
        foreach ($rows as $keyword) {
            $kid = (int) $keyword->id;
            $phrase = Keyword::decodePhrase((string) ($keyword->phrase ?? ''));
            if ($phrase === '') {
                continue;
            }
            $evidence = $evidenceByKeyword[$kid] ?? null;
            $articleId = is_array($evidence) ? ((int) ($evidence['article_id'] ?? 0) ?: null) : null;
            $group = is_array($evidence) ? trim((string) ($evidence['vocabulary_group'] ?? '')) : '';
            $group = $group !== '' ? $group : null;

            $out[] = new IdeaCandidate(
                candidateRef: IdeaCandidate::ref(IdeaCandidateSource::KEY_VOCABULARY_SUGGEST, $kid),
                keywordId: $kid,
                phrase: $phrase,
                source: IdeaCandidateSource::KEY_VOCABULARY_SUGGEST,
                sourceLabel: $sourceLabel,
                sourceArticleId: $articleId,
                sourceArticleTitle: null,
                vocabularyGroup: $group,
                vocabularyGroupLabel: $group !== null ? $this->groupLabel($group) : null,
            );
        }

        return $out;
    }

    /**
     * @return Builder<Keyword>
     */
    private function baseVocabularyQuery(int $siteId): Builder
    {
        $query = VocabularySuggestStagingQuery::forSite($siteId);

        $query->where(function (Builder $q): void {
            $q->whereNull('review_status')
                ->orWhereNotIn('review_status', [
                    KeywordReviewStatus::Warning->value,
                    KeywordReviewStatus::Danger->value,
                ]);
        });

        if (Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            $query->whereDoesntHave('metas', static function (Builder $q): void {
                $q->where('meta_key', KeywordMetaKey::SeoHidden->value)
                    ->where('meta_value', '1');
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Keyword>  $query
     */
    private function applyPhraseSearch(Builder $query, string $search): void
    {
        $needle = mb_strtolower(trim($search), 'UTF-8');
        if ($needle === '') {
            return;
        }

        $query->whereRaw('LOWER(phrase) LIKE ?', ['%'.$needle.'%']);
    }

    /**
     * @param  Builder<Keyword>  $query
     */
    private function excludePlannedCreateDuplicates(Builder $query, SeoProject $draft): void
    {
        $rawLowers = $this->plannedCreatePhraseLowers($draft);
        if ($rawLowers === []) {
            return;
        }

        $keys = array_keys($rawLowers);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $query->whereRaw('LOWER(TRIM(phrase)) NOT IN ('.$placeholders.')', $keys);
    }

    /**
     * @return array<string, true>
     */
    private function plannedCreatePhraseLowers(SeoProject $draft): array
    {
        $projectId = (int) $draft->getKey();
        if ($projectId <= 0) {
            return [];
        }

        $out = [];
        $rows = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->where('type', SeoProjectTask::TYPE_CREATE)
            ->get(['keyword', 'title', 'source_content']);

        foreach ($rows as $task) {
            foreach ([(string) ($task->keyword ?? ''), (string) ($task->title ?? ''), (string) ($task->source_content ?? '')] as $raw) {
                $lower = mb_strtolower(trim($raw), 'UTF-8');
                if ($lower !== '') {
                    $out[$lower] = true;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, true>
     */
    public function plannedCreateKeywordNorms(SeoProject $project): array
    {
        $projectId = (int) $project->getKey();
        if ($projectId <= 0) {
            return [];
        }

        $norms = [];
        $rows = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->where('type', SeoProjectTask::TYPE_CREATE)
            ->get(['keyword', 'title', 'source_content']);

        foreach ($rows as $task) {
            foreach ([(string) ($task->keyword ?? ''), (string) ($task->title ?? ''), (string) ($task->source_content ?? '')] as $raw) {
                $norm = NewContentSuggestionIdentity::normalize($raw);
                if ($norm !== '') {
                    $norms[$norm] = true;
                }
            }
        }

        return $norms;
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, array{article_id?: int, vocabulary_group?: string}>
     */
    private function loadEvidenceMap(array $keywordIds, int $siteId): array
    {
        $keywordIds = array_values(array_filter(array_map('intval', $keywordIds)));
        if ($keywordIds === [] || $siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return [];
        }

        $prefix = 'site.'.$siteId.'.vocab_evidence.';
        $metas = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->whereIn('keyword_id', $keywordIds)
            ->where('meta_key', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->get(['keyword_id', 'meta_key', 'meta_value']);

        $map = [];
        foreach ($metas as $meta) {
            $kid = (int) ($meta->keyword_id ?? 0);
            if ($kid <= 0 || isset($map[$kid])) {
                continue;
            }
            $decoded = json_decode((string) ($meta->meta_value ?? ''), true);
            if (! is_array($decoded) || $decoded === []) {
                continue;
            }
            $first = $decoded[0] ?? null;
            if (! is_array($first)) {
                continue;
            }
            $groupFromKey = substr((string) ($meta->meta_key ?? ''), strlen($prefix));
            $map[$kid] = [
                'article_id' => (int) ($first['article_id'] ?? 0),
                'vocabulary_group' => trim((string) ($first['vocabulary_group'] ?? $groupFromKey)),
            ];
        }

        return $map;
    }

    /**
     * @param  list<int>  $articleIds
     * @return array<int, string>
     */
    private function loadArticleTitles(array $articleIds, int $siteId): array
    {
        $articleIds = array_values(array_filter(array_map('intval', $articleIds)));
        if ($articleIds === [] || $siteId <= 0) {
            return [];
        }

        $out = [];
        $rows = SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $articleIds)
            ->get(['id', 'title']);
        foreach ($rows as $article) {
            $title = trim((string) ($article->title ?? ''));
            if ($title !== '') {
                $out[(int) $article->id] = $title;
            }
        }

        return $out;
    }

    private function groupLabel(string $canonicalGroup): string
    {
        return self::GROUP_LABELS[$canonicalGroup] ?? str_replace('_', ' ', $canonicalGroup);
    }
}
