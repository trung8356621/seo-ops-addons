<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use App\Support\RuntimeLogger;
use Filament\Notifications\Notification;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddIdeaCandidatesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\DismissVocabularySuggestCandidateService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateDraftPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateQueryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateSource;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditExistingContentSuggestionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionFilterSet;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Throwable;

/**
 * Project Planner — select Idea Candidates (Vocabulary Suggest) then Create / Rewrite / Improve.
 * No AI calls.
 */
trait InteractsWithIdeaCandidates
{
    /** @var list<int> */
    public array $selectedIdeaKeywordIds = [];

    public string $ideaCandidateSearch = '';

    public string $ideaCandidateSource = IdeaCandidateSource::KEY_VOCABULARY_SUGGEST;

    public string $ideaCandidatesLastResult = '';

    /** null|rewrite|improve — when set, show article picker */
    public ?string $ideaPendingAction = null;

    /** @var list<int> */
    public array $selectedIdeaArticleIds = [];

    public string $ideaArticleSearch = '';

    public function mountInteractsWithIdeaCandidates(): void
    {
        $this->selectedIdeaKeywordIds = [];
        $this->ideaCandidateSearch = '';
        $this->ideaCandidateSource = IdeaCandidateSource::KEY_VOCABULARY_SUGGEST;
        $this->ideaCandidatesLastResult = '';
        $this->ideaPendingAction = null;
        $this->selectedIdeaArticleIds = [];
        $this->ideaArticleSearch = '';
        $this->resetPage('ideaCandidatesPage');
        $this->resetPage('ideaArticlesPage');
    }

    protected function resolveIdeaCandidateProject(): ?SeoProject
    {
        if (method_exists($this, 'resolvePlannerProject')) {
            /** @var callable $resolver */
            $resolver = [$this, 'resolvePlannerProject'];
            $project = $resolver();

            return $project instanceof SeoProject ? $project : null;
        }

        return property_exists($this, 'project') && $this->project instanceof SeoProject
            ? $this->project
            : null;
    }

    public function updatedIdeaCandidateSearch(): void
    {
        $this->resetPage('ideaCandidatesPage');
    }

    public function updatedIdeaCandidateSource(): void
    {
        $this->resetPage('ideaCandidatesPage');
        $this->selectedIdeaKeywordIds = [];
    }

    public function updatedIdeaArticleSearch(): void
    {
        $this->resetPage('ideaArticlesPage');
    }

    public function toggleIdeaCandidate(int $keywordId): void
    {
        if ($keywordId <= 0) {
            return;
        }

        $ids = array_map('intval', $this->selectedIdeaKeywordIds);
        if (in_array($keywordId, $ids, true)) {
            $this->selectedIdeaKeywordIds = array_values(array_filter(
                $ids,
                static fn (int $id): bool => $id !== $keywordId,
            ));

            return;
        }

        $ids[] = $keywordId;
        $this->selectedIdeaKeywordIds = array_values(array_unique($ids));
    }

    public function clearIdeaCandidateSelection(): void
    {
        $this->selectedIdeaKeywordIds = [];
        $this->closeIdeaArticlePicker();
    }

    public function dismissIdeaCandidate(int $keywordId): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        if ($keywordId <= 0) {
            return;
        }

        $project = $this->resolveIdeaCandidateProject();
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0 && $project instanceof SeoProject) {
            $siteId = (int) ($project->site_id ?? 0);
        }

        try {
            app(DismissVocabularySuggestCandidateService::class)->dismiss($keywordId, $siteId);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.dismiss_idea_candidate',
                'keyword_id' => $keywordId,
                'site_id' => $siteId,
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.idea_candidate_delete_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->selectedIdeaKeywordIds = array_values(array_filter(
            array_map('intval', $this->selectedIdeaKeywordIds),
            static fn (int $id): bool => $id !== $keywordId,
        ));

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.idea_candidate_deleted'))
            ->success()
            ->send();
    }

    public function addIdeaCandidatesAsCreate(): void
    {
        $this->dispatchAddIdeaCandidates(IdeaCandidateDraftPlannerService::ACTION_CREATE);
    }

    public function openIdeaRewritePicker(): void
    {
        if ($this->selectedIdeaKeywordIds === []) {
            return;
        }
        $this->ideaPendingAction = IdeaCandidateDraftPlannerService::ACTION_REWRITE;
        $this->selectedIdeaArticleIds = [];
        $this->ideaArticleSearch = '';
        $this->resetPage('ideaArticlesPage');
    }

    public function openIdeaImprovePicker(): void
    {
        if ($this->selectedIdeaKeywordIds === []) {
            return;
        }
        $this->ideaPendingAction = IdeaCandidateDraftPlannerService::ACTION_IMPROVE;
        $this->selectedIdeaArticleIds = [];
        $this->ideaArticleSearch = '';
        $this->resetPage('ideaArticlesPage');
    }

    public function closeIdeaArticlePicker(): void
    {
        $this->ideaPendingAction = null;
        $this->selectedIdeaArticleIds = [];
        $this->ideaArticleSearch = '';
    }

    public function toggleIdeaArticle(int $articleId): void
    {
        if ($articleId <= 0) {
            return;
        }

        $ids = array_map('intval', $this->selectedIdeaArticleIds);
        if (in_array($articleId, $ids, true)) {
            $this->selectedIdeaArticleIds = array_values(array_filter(
                $ids,
                static fn (int $id): bool => $id !== $articleId,
            ));

            return;
        }

        $ids[] = $articleId;
        $this->selectedIdeaArticleIds = array_values(array_unique($ids));
    }

    public function confirmIdeaArticleAction(): void
    {
        $action = (string) ($this->ideaPendingAction ?? '');
        if (! in_array($action, [
            IdeaCandidateDraftPlannerService::ACTION_REWRITE,
            IdeaCandidateDraftPlannerService::ACTION_IMPROVE,
        ], true)) {
            return;
        }

        $this->dispatchAddIdeaCandidates($action, $this->selectedIdeaArticleIds);
    }

    /**
     * @param  list<int>  $articleIds
     */
    private function dispatchAddIdeaCandidates(string $action, array $articleIds = []): void
    {
        abort_unless(SeoAccessControl::canManageContentProjectWorkflow(), 403);

        $project = $this->resolveIdeaCandidateProject();
        if (! $project instanceof SeoProject) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.seo_audit_draft_empty'))
                ->warning()
                ->send();

            return;
        }

        if (! $project->isDraftPlanning()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.suggestions_draft_only'))
                ->warning()
                ->send();

            return;
        }

        $keywordIds = array_values(array_unique(array_filter(array_map('intval', $this->selectedIdeaKeywordIds))));
        if ($keywordIds === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.idea_candidate_select_required'))
                ->warning()
                ->send();

            return;
        }

        if (in_array($action, [
            IdeaCandidateDraftPlannerService::ACTION_REWRITE,
            IdeaCandidateDraftPlannerService::ACTION_IMPROVE,
        ], true) && $articleIds === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.idea_candidate_article_required'))
                ->warning()
                ->send();

            return;
        }

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                new AddIdeaCandidatesCommand(
                    (int) $project->getKey(),
                    $keywordIds,
                    $action,
                    $articleIds,
                ),
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    (int) ($project->site_id ?? 0) ?: null,
                ),
            );
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.add_idea_candidates',
                'project_id' => (int) $project->getKey(),
                'action' => $action,
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.idea_candidate_add_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->ideaCandidatesLastResult = $result->message;

        if ($result->success || $result->code === ContentProjectActionCodes::IDEA_CANDIDATES_ADDED) {
            $added = (int) ($result->metadata['added'] ?? 0);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.idea_candidate_add_done', ['count' => $added]))
                ->body($result->message)
                ->success()
                ->send();

            $this->selectedIdeaKeywordIds = [];
            $this->closeIdeaArticlePicker();
            $this->resetPage('ideaCandidatesPage');
            $this->dispatch('cp-ops-refresh');
            if (method_exists($this, 'mountInteractsWithNewContentSuggestions')) {
                $this->mountInteractsWithNewContentSuggestions();
            }
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.idea_candidate_add_failed'))
                ->body($result->message)
                ->warning()
                ->send();
        }
    }

    /**
     * @return array{
     *   can_write: bool,
     *   has_project: bool,
     *   is_draft: bool,
     *   sources: list<array{key: string, label: string}>,
     *   rows: list<array<string, mixed>>,
     *   paginator: mixed,
     *   selected_count: int,
     *   pending_action: string|null,
     *   article_rows: list<array<string, mixed>>,
     *   article_paginator: mixed,
     *   selected_article_count: int
     * }
     */
    public function getIdeaCandidatesPayloadProperty(): array
    {
        $project = $this->resolveIdeaCandidateProject();
        $siteId = (int) ($this->filterSiteId ?? 0);
        if ($siteId <= 0 && $project instanceof SeoProject) {
            $siteId = (int) ($project->site_id ?? 0);
        }

        $canWrite = SeoAccessControl::canManageContentProjectWorkflow()
            && $project instanceof SeoProject
            && $project->isDraftPlanning();

        $query = app(IdeaCandidateQueryService::class);
        $page = max(1, (int) $this->getPage('ideaCandidatesPage'));
        $paginator = $query->paginate(
            $siteId,
            $project instanceof SeoProject ? $project : null,
            [
                'source' => $this->ideaCandidateSource,
                'search' => $this->ideaCandidateSearch,
                'exclude_draft_duplicates' => true,
            ],
            $page,
            IdeaCandidateQueryService::PER_PAGE_DEFAULT,
        );

        $articleRows = [];
        $articlePaginator = null;
        if ($this->ideaPendingAction !== null && $project instanceof SeoProject) {
            $filters = SeoAuditSuggestionFilterSet::defaults();
            $filters['search'] = $this->ideaArticleSearch;
            $filters['state'] = SeoAuditExistingContentSuggestionService::STATE_AVAILABLE;
            $filters['only_with_issues'] = false;
            $filters['score_max'] = null;
            $articlePaginator = app(SeoAuditExistingContentSuggestionService::class)->paginate(
                $project,
                $filters,
                max(1, (int) $this->getPage('ideaArticlesPage')),
                10,
            );
            $articleRows = array_map(static function (mixed $row): array {
                if (! is_array($row)) {
                    return [];
                }

                return [
                    'article_id' => (int) ($row['article_id'] ?? 0),
                    'title' => (string) ($row['title'] ?? ''),
                    'seo_score' => $row['seo_score'] ?? null,
                    'suggested_action' => (string) ($row['suggested_action'] ?? ''),
                ];
            }, $articlePaginator->items());
        }

        return [
            'can_write' => $canWrite,
            'has_project' => $project instanceof SeoProject,
            'is_draft' => $project instanceof SeoProject && $project->isDraftPlanning(),
            'sources' => $query->sourceOptions(),
            'rows' => array_values(array_filter(
                array_map(
                    static fn (mixed $row): array => is_array($row) ? $row : [],
                    $paginator->items(),
                ),
            )),
            'paginator' => $paginator,
            'selected_count' => count($this->selectedIdeaKeywordIds),
            'pending_action' => $this->ideaPendingAction,
            'article_rows' => $articleRows,
            'article_paginator' => $articlePaginator,
            'selected_article_count' => count($this->selectedIdeaArticleIds),
        ];
    }
}
