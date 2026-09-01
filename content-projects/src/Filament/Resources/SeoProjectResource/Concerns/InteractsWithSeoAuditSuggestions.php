<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns;

use Omnichannel\Addons\Content\Support\PublishCategoryOptionsAssembler;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\CreateContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\DismissSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\FillSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestoreSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipSeoAuditArticlesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditExistingContentSuggestionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionFilterSet;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

/**
 * Existing-content Suggestions workspace — shared by ViewSeoProject and ContentProjectSeoAuditPlanner.
 *
 * Host must provide requireProject() and use Livewire WithPagination.
 * Optional: override resolvePlannerProject() when project may be unset (global planner).
 */
trait InteractsWithSeoAuditSuggestions
{
    public string $workspaceTab = 'items';

    /** Future: new|performance — v1 Existing only. */
    public string $suggestionLane = 'existing';

    public string $suggestionSearch = '';

    public string $suggestionSearchInput = '';

    /** Score preset max: null|'' = All, otherwise <80 / <60 / <40. */
    public string|int|null $suggestionScoreMax = null;

    public string $suggestionIssueKey = '';

    public string $suggestedAction = '';

    /** available|dismissed|planned — Available excludes dismissed+planned. */
    public string $suggestionStateFilter = 'available';

    /** primary|all */
    public string $suggestionLanguageScope = 'primary';

    /** all_except_page|all|specific */
    public string $suggestionPostTypeMode = SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL_EXCEPT_PAGE;

    public string $suggestionPostType = '';

    /** category|product_category|'' */
    public string $suggestionTaxonomy = '';

    public string|int|null $suggestionTermId = null;

    /** @deprecated Prefer suggestionStateFilter */
    public bool $showDismissed = false;

    /** @deprecated Prefer suggestionStateFilter */
    public bool $showPlanned = false;

    /** @var list<int> */
    public array $selectedSuggestionArticleIds = [];

    /** @var array<int|string, string> article_id => rewrite|improve */
    public array $suggestionActionByArticle = [];

    public string|int $fillLimit = 20;

    public int $suggestionsPerPage = 25;

    public string $suggestionsLastResult = '';

    abstract protected function requireProject(): SeoProject;

    /**
     * Soft resolve for read/empty-state. Override on global planner when no draft selected.
     */
    protected function resolvePlannerProject(): ?SeoProject
    {
        try {
            return $this->requireProject();
        } catch (Halt) {
            return null;
        }
    }

    public function mountInteractsWithSeoAuditSuggestions(): void
    {
        $workspace = strtolower(trim((string) request()->query('workspace', '')));
        if ($workspace === '') {
            $workspace = strtolower(trim((string) request()->query('tab', '')));
        }

        if (in_array($workspace, ['suggestions', 'items', 'planner'], true)) {
            $this->workspaceTab = $workspace === 'planner' ? 'suggestions' : $workspace;
        }

        $this->normalizeSuggestionStateFilter();
        $this->hydrateSuggestionFiltersFromHistory();
    }

    public function setWorkspaceTab(string $tab): void
    {
        $normalized = strtolower(trim($tab));
        if (! in_array($normalized, ['items', 'suggestions'], true)) {
            return;
        }

        $this->workspaceTab = $normalized;
        if ($normalized === 'suggestions') {
            $this->clearSuggestionSelection();
            $this->resetPage('suggestionsPage');
        }
    }

    /**
     * Compact chips reflecting the current Improve filter snapshot (no candidate query).
     *
     * @return list<string>
     */
    public function getImproveRecentFilterChipsProperty(): array
    {
        $chips = [];
        $scoreMax = $this->suggestionScoreMax;
        if ($scoreMax !== null && $scoreMax !== '') {
            $chips[] = 'Score < '.(int) $scoreMax;
        }
        $mode = (string) ($this->suggestionPostTypeMode ?? '');
        if ($mode === SeoAuditSuggestionFilterSet::POST_TYPE_MODE_SPECIFIC && trim((string) $this->suggestionPostType) !== '') {
            $chips[] = (string) $this->suggestionPostType;
        } elseif ($mode === SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL) {
            $chips[] = (string) __('seo-content-ai::filament.article_list.all_post_types');
        } else {
            $chips[] = (string) __('seo-content-ai::filament.projects.planner_post_type_except_page');
        }
        $chips[] = ((string) ($this->suggestionLanguageScope ?? 'primary') === 'all')
            ? (string) __('seo-content-ai::filament.projects.suggestions_filter_language_all')
            : (string) __('seo-content-ai::filament.projects.planner_chip_primary_language');
        $chips[] = (string) __('seo-content-ai::filament.projects.suggestions_state_available');

        return array_values(array_filter($chips, static fn (string $c): bool => trim($c) !== ''));
    }

    /**
     * Lightweight card state for Content Planning (no SEO Audit candidate pagination).
     *
     * @return array{
     *   can_write: bool,
     *   has_project: bool,
     *   is_draft: bool,
     *   primary_configured: bool,
     *   primary_language_label: ?string,
     *   domain_edit_url: ?string
     * }
     */
    public function getSeoAuditPlannerCardStateProperty(): array
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            return [
                'can_write' => false,
                'has_project' => false,
                'is_draft' => false,
                'primary_configured' => false,
                'primary_language_label' => null,
                'domain_edit_url' => null,
            ];
        }

        $primaryMeta = $this->primaryLanguagePayloadForProject($project);
        $isDraft = $project->isDraftPlanning();

        return [
            'can_write' => $isDraft,
            'has_project' => true,
            'is_draft' => $isDraft,
            'primary_configured' => (bool) ($primaryMeta['primary_configured'] ?? false),
            'primary_language_label' => $primaryMeta['primary_language_label'] ?? null,
            'domain_edit_url' => $primaryMeta['domain_edit_url'] ?? null,
        ];
    }

    /**
     * @return array{
     *   can_write: bool,
     *   has_project: bool,
     *   is_draft: bool,
     *   rows: list<array<string, mixed>>,
     *   paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator,
     *   issue_options: list<array{key: string, label: string}>,
     *   filters: array<string, mixed>,
     *   summary: array{matched: int, selected: int, available: int, dismissed: int, planned: int}
     * }
     */
    public function getSuggestionsPayloadProperty(): array
    {
        $emptyPaginator = new LengthAwarePaginator([], 0, max(1, (int) $this->suggestionsPerPage), 1);
        $emptyPaginator->setPageName('suggestionsPage');

        $issueOptions = array_map(
            static fn (array $def): array => [
                'key' => (string) ($def['key'] ?? ''),
                'label' => (string) ($def['label'] ?? $def['key'] ?? ''),
            ],
            SeoScoringRulesRegistry::auditFilterDefinitions(),
        );

        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            return [
                'can_write' => false,
                'has_project' => false,
                'is_draft' => false,
                'rows' => [],
                'paginator' => $emptyPaginator,
                'issue_options' => $issueOptions,
                'filters' => $this->buildSuggestionFilters(),
                'primary_configured' => false,
                'primary_language_label' => null,
                'domain_edit_url' => null,
                'summary' => [
                    'matched' => 0,
                    'selected' => count($this->normalizeSuggestionIds($this->selectedSuggestionArticleIds)),
                    'available' => 0,
                    'dismissed' => 0,
                    'planned' => 0,
                ],
            ];
        }

        $filters = $this->buildSuggestionFilters();
        $page = max(1, (int) $this->getPage('suggestionsPage'));
        $perPage = max(1, min(100, (int) $this->suggestionsPerPage));

        $paginator = app(SeoAuditExistingContentSuggestionService::class)
            ->paginate($project, $filters, $page, $perPage);
        $paginator->setPageName('suggestionsPage');

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter(
            $paginator->items(),
            static fn (mixed $row): bool => is_array($row),
        ));

        $state = $this->normalizedSuggestionStateFilter();
        if (in_array($state, ['dismissed', 'planned'], true)) {
            $rows = array_values(array_filter($rows, function (array $row) use ($state): bool {
                $rowState = (string) ($row['state'] ?? '');
                if ($state === 'dismissed') {
                    return $rowState === SeoAuditExistingContentSuggestionService::STATE_DISMISSED;
                }

                return in_array($rowState, [
                    SeoAuditExistingContentSuggestionService::STATE_PLANNED,
                    SeoAuditExistingContentSuggestionService::STATE_PLANNED_OTHER,
                ], true);
            }));
        }

        $isDraft = $project->isDraftPlanning();
        $selectedCount = count($this->normalizeSuggestionIds($this->selectedSuggestionArticleIds));
        $primaryMeta = $this->primaryLanguagePayloadForProject($project);

        return [
            'can_write' => $isDraft,
            'has_project' => true,
            'is_draft' => $isDraft,
            'rows' => $rows,
            'paginator' => $paginator,
            'issue_options' => $issueOptions,
            'filters' => $filters,
            'primary_configured' => $primaryMeta['primary_configured'],
            'primary_language_label' => $primaryMeta['primary_language_label'],
            'domain_edit_url' => $primaryMeta['domain_edit_url'],
            'summary' => [
                'matched' => (int) $paginator->total(),
                'selected' => $selectedCount,
                'available' => $state === 'available' ? (int) $paginator->total() : 0,
                'dismissed' => $state === 'dismissed' ? (int) $paginator->total() : 0,
                'planned' => $state === 'planned' ? (int) $paginator->total() : 0,
            ],
        ];
    }

    public function applySuggestionSearch(): void
    {
        $this->suggestionSearch = trim($this->suggestionSearchInput);
        $this->suggestionSearchInput = $this->suggestionSearch;
        $this->resetSuggestionsPage();
    }

    public function clearSuggestionSearch(): void
    {
        $this->suggestionSearch = '';
        $this->suggestionSearchInput = '';
        $this->resetSuggestionsPage();
    }

    public function updatedSuggestionScoreMax(): void
    {
        $this->resetSuggestionsPage();
    }

    public function updatedSuggestionIssueKey(): void
    {
        $this->resetSuggestionsPage();
    }

    public function updatedSuggestedAction(): void
    {
        $this->resetSuggestionsPage();
    }

    public function updatedSuggestionStateFilter(): void
    {
        $this->normalizeSuggestionStateFilter();
        $this->resetSuggestionsPage();
    }

    public function updatedSuggestionLanguageScope(): void
    {
        $scope = strtolower(trim($this->suggestionLanguageScope));
        $this->suggestionLanguageScope = in_array($scope, ['primary', 'all'], true) ? $scope : 'primary';
        $this->resetSuggestionsPage();
    }

    public function updatedSuggestionPostTypeMode(): void
    {
        $mode = strtolower(trim($this->suggestionPostTypeMode));
        $this->suggestionPostTypeMode = in_array($mode, [
            SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL_EXCEPT_PAGE,
            SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL,
            SeoAuditSuggestionFilterSet::POST_TYPE_MODE_SPECIFIC,
        ], true) ? $mode : SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL_EXCEPT_PAGE;
        $this->resetSuggestionsPage();
    }

    public function updatedSuggestionPostType(): void
    {
        $this->suggestionPostType = strtolower(trim($this->suggestionPostType));
        if ($this->suggestionPostType !== '') {
            $this->suggestionPostTypeMode = SeoAuditSuggestionFilterSet::POST_TYPE_MODE_SPECIFIC;
        }
        $this->resetSuggestionsPage();
    }

    public function updatedSuggestionTaxonomy(): void
    {
        $taxonomy = strtolower(trim($this->suggestionTaxonomy));
        if ($taxonomy === 'product_cat') {
            $taxonomy = 'product_category';
        }
        $this->suggestionTaxonomy = in_array($taxonomy, ['category', 'product_category'], true) ? $taxonomy : '';
        $this->suggestionTermId = null;
        $this->resetSuggestionsPage();
    }

    public function updatedSuggestionTermId(): void
    {
        $this->resetSuggestionsPage();
    }

    public function updatedShowDismissed(): void
    {
        $this->suggestionStateFilter = $this->showDismissed ? 'dismissed' : 'available';
        $this->normalizeSuggestionStateFilter();
        $this->resetSuggestionsPage();
    }

    public function updatedShowPlanned(): void
    {
        $this->suggestionStateFilter = $this->showPlanned ? 'planned' : 'available';
        $this->normalizeSuggestionStateFilter();
        $this->resetSuggestionsPage();
    }

    public function setSuggestionScorePreset(string|int|null $max): void
    {
        if ($max === '' || $max === null || $max === 'all') {
            $this->suggestionScoreMax = null;
        } else {
            $this->suggestionScoreMax = (int) $max;
        }
        $this->resetSuggestionsPage();
    }

    public function toggleSuggestionSelection(int $articleId): void
    {
        $id = max(0, $articleId);
        if ($id <= 0) {
            return;
        }

        $ids = $this->normalizeSuggestionIds($this->selectedSuggestionArticleIds);
        if (in_array($id, $ids, true)) {
            $this->selectedSuggestionArticleIds = array_values(array_filter(
                $ids,
                static fn (int $existing): bool => $existing !== $id,
            ));

            return;
        }

        $ids[] = $id;
        $this->selectedSuggestionArticleIds = $ids;
    }

    public function selectAllVisibleSuggestions(): void
    {
        $ids = $this->normalizeSuggestionIds($this->selectedSuggestionArticleIds);
        foreach ($this->suggestionsPayload['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['article_id'] ?? 0);
            $addDisabled = (bool) ($row['add_disabled'] ?? false);
            if ($id > 0 && ! $addDisabled && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $this->selectedSuggestionArticleIds = $ids;
    }

    public function clearSuggestionSelection(): void
    {
        $this->selectedSuggestionArticleIds = [];
    }

    public function setSuggestionAction(int $articleId, string $action): void
    {
        $id = max(0, $articleId);
        $normalized = strtolower(trim($action));
        if ($id <= 0 || ! in_array($normalized, [
            SeoAuditExistingContentSuggestionService::ACTION_REWRITE,
            SeoAuditExistingContentSuggestionService::ACTION_IMPROVE,
        ], true)) {
            return;
        }

        $this->suggestionActionByArticle[$id] = $normalized;
    }

    public function addSelectedSuggestions(): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            $this->notifyPlannerProjectRequired();

            return;
        }
        if (! $project->isDraftPlanning()) {
            $this->notifySuggestionsNotDraft();

            return;
        }

        $ids = $this->normalizeSuggestionIds($this->selectedSuggestionArticleIds);
        if ($ids === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.suggestions_select_required'))
                ->warning()
                ->send();

            return;
        }

        $this->addSuggestionRowsByIds($ids);
    }

    public function addOneSuggestion(int $articleId): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            $this->notifyPlannerProjectRequired();

            return;
        }
        if (! $project->isDraftPlanning()) {
            $this->notifySuggestionsNotDraft();

            return;
        }

        $id = max(0, $articleId);
        if ($id <= 0) {
            return;
        }

        $this->addSuggestionRowsByIds([$id]);
    }

    /**
     * @param  list<int>  $ids
     */
    protected function addSuggestionRowsByIds(array $ids): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            return;
        }

        $rowsById = [];
        foreach ($this->suggestionsPayload['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $aid = (int) ($row['article_id'] ?? 0);
            if ($aid > 0) {
                $rowsById[$aid] = $row;
            }
        }

        $commandRows = [];
        foreach ($ids as $articleId) {
            $row = $rowsById[$articleId] ?? null;
            if (! is_array($row) || (bool) ($row['add_disabled'] ?? false)) {
                continue;
            }

            $action = (string) ($this->suggestionActionByArticle[$articleId]
                ?? $row['suggested_action']
                ?? SeoAuditExistingContentSuggestionService::ACTION_IMPROVE);

            $commandRows[] = [
                'article_id' => $articleId,
                'action' => $action,
                'reason_codes' => is_array($row['reason_codes'] ?? null) ? $row['reason_codes'] : [],
                'recommendation_summary' => (string) ($row['recommendation_summary'] ?? ''),
            ];
        }

        if ($commandRows === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.suggestions_select_required'))
                ->warning()
                ->send();

            return;
        }

        $result = $this->dispatchSuggestionCommand(new AddSeoAuditSuggestionsCommand(
            (int) $project->getKey(),
            $commandRows,
        ));

        if ($result?->success) {
            $this->clearSuggestionSelection();
            $this->resetSuggestionsPage();
        }
    }

    public function dismissSelectedSuggestions(): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            $this->notifyPlannerProjectRequired();

            return;
        }
        if (! $project->isDraftPlanning()) {
            $this->notifySuggestionsNotDraft();

            return;
        }

        $ids = $this->normalizeSuggestionIds($this->selectedSuggestionArticleIds);
        if ($ids === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.suggestions_select_required'))
                ->warning()
                ->send();

            return;
        }

        $result = $this->dispatchSuggestionCommand(new DismissSeoAuditSuggestionsCommand(
            (int) $project->getKey(),
            $ids,
        ));

        if ($result?->success) {
            $this->clearSuggestionSelection();
            $this->resetSuggestionsPage();
        }
    }

    public function restoreSuggestion(int $articleId): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            $this->notifyPlannerProjectRequired();

            return;
        }
        if (! $project->isDraftPlanning()) {
            $this->notifySuggestionsNotDraft();

            return;
        }

        $id = max(0, $articleId);
        if ($id <= 0) {
            return;
        }

        $result = $this->dispatchSuggestionCommand(new RestoreSeoAuditSuggestionsCommand(
            (int) $project->getKey(),
            [$id],
        ));

        if ($result?->success) {
            $this->resetSuggestionsPage();
        }
    }

    public function skipSuggestionFromSeoAudit(int $articleId): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            $this->notifyPlannerProjectRequired();

            return;
        }

        $id = max(0, $articleId);
        if ($id <= 0) {
            return;
        }

        $result = $this->dispatchSuggestionCommand(new SkipSeoAuditArticlesCommand(
            (int) $project->getKey(),
            [$id],
        ));

        if ($result?->success) {
            $this->clearSuggestionSelection();
            $this->resetSuggestionsPage();
        }
    }

    public function dismissSuggestion(int $articleId): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            $this->notifyPlannerProjectRequired();

            return;
        }
        if (! $project->isDraftPlanning()) {
            $this->notifySuggestionsNotDraft();

            return;
        }

        $id = max(0, $articleId);
        if ($id <= 0) {
            return;
        }

        $result = $this->dispatchSuggestionCommand(new DismissSeoAuditSuggestionsCommand(
            (int) $project->getKey(),
            [$id],
        ));

        if ($result?->success) {
            $this->resetSuggestionsPage();
        }
    }

    public function fillSuggestions(): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            $this->notifyPlannerProjectRequired();

            return;
        }
        if (! $project->isDraftPlanning()) {
            $this->notifySuggestionsNotDraft();

            return;
        }

        $limit = is_string($this->fillLimit) && strtolower(trim((string) $this->fillLimit)) === 'all'
            ? 'all'
            : max(1, (int) $this->fillLimit);

        $result = $this->dispatchSuggestionCommand(new FillSeoAuditSuggestionsCommand(
            (int) $project->getKey(),
            $this->buildSuggestionFilters(),
            $limit,
        ));

        if ($result?->success) {
            $this->clearSuggestionSelection();
            $this->resetSuggestionsPage();
            if (method_exists($this, 'dispatch')) {
                $this->dispatch('cp-ops-refresh');
            }
        }
    }

    public function saveSeoAuditFilters(): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            $this->notifyPlannerProjectRequired();

            return;
        }

        $filters = $this->buildSuggestionFilters();
        app(ContentProjectPlannerRunService::class)->recordSavedConfig(
            $project,
            SeoContentProjectPlannerRun::SOURCE_SEO_AUDIT,
            SeoAuditSuggestionFilterSet::snapshot($filters),
            auth()->id() !== null ? (int) auth()->id() : null,
        );

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.planner_filters_saved'))
            ->success()
            ->send();
    }

    /**
     * @return array{post_types: array<string, string>, taxonomies: array<string, string>, terms: list<array{id:int,label:string}>}
     */
    public function getSuggestionFilterOptionsProperty(): array
    {
        $project = $this->resolvePlannerProject();
        $siteId = (int) ($project?->site_id ?? 0);

        $postTypes = [
            'post' => (string) __('seo-content-ai::filament.article_list.post_type_post'),
            'page' => (string) __('seo-content-ai::filament.article_list.post_type_page'),
            'product' => (string) __('seo-content-ai::filament.article_list.post_type_product'),
        ];

        $taxonomies = [
            'category' => (string) __('seo-content-ai::filament.article_list.post_type_category'),
            'product_category' => (string) __('seo-content-ai::filament.article_list.post_type_product_category'),
        ];

        $terms = [];
        if ($siteId > 0) {
            try {
                $catalog = app(PublishCategoryOptionsAssembler::class)->forSite($siteId);
                $key = $this->suggestionTaxonomy === 'product_category' ? 'product_category' : 'category';
                if ($this->suggestionTaxonomy !== '') {
                    $terms = is_array($catalog[$key] ?? null) ? $catalog[$key] : [];
                }
            } catch (Throwable) {
                $terms = [];
            }
        }

        return [
            'post_types' => $postTypes,
            'taxonomies' => $taxonomies,
            'terms' => $terms,
        ];
    }

    public function refreshAuditSuggestions(): void
    {
        $this->resetSuggestionsPage();

        $project = $this->resolvePlannerProject();
        $params = [];
        if ($project instanceof SeoProject) {
            $params['project'] = (int) $project->getKey();
            $siteId = (int) ($project->site_id ?? 0);
            if ($siteId > 0) {
                $params['site'] = $siteId;
            }
        }

        $auditUrl = ContentProjectSeoAuditPlanner::getUrl($params);
        Notification::make()
            ->title(__('seo-content-ai::filament.projects.suggestions_using_stored_audit'))
            ->body(__('seo-content-ai::filament.projects.suggestions_refresh_audit_help'))
            ->actions([
                \Filament\Notifications\Actions\Action::make('open_audit')
                    ->label(__('seo-content-ai::filament.projects.suggestions_open_seo_audit'))
                    ->url($auditUrl, shouldOpenInNewTab: true),
            ])
            ->info()
            ->send();

        $this->suggestionsLastResult = (string) __('seo-content-ai::filament.projects.suggestions_using_stored_audit');
    }

    public function createDraftProjectForSuggestions(): void
    {
        $bootstrap = (int) ($this->filterSiteId ?? 0);
        if ($bootstrap <= 0) {
            $project = $this->resolvePlannerProject();
            $bootstrap = $project instanceof SeoProject ? (int) ($project->site_id ?? 0) : 0;
        }
        if ($bootstrap <= 0) {
            $bootstrap = (int) (array_key_first($this->siteFilterOptions ?? []) ?? 0);
        }

        try {
            $draft = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService::class)
                ->ensureSharedDraft(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    $bootstrap,
                );
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.suggestions.ensure_shared_draft',
                'bootstrap_site_id' => $bootstrap,
            ]);
            Notification::make()
                ->title(__('seo-content-ai::filament.projects.suggestions_create_draft_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.suggestions_draft_reused'))
            ->success()
            ->send();

        $this->redirect(SeoProjectResource::getUrl('view', [
            'record' => (int) $draft->getKey(),
            'workspace' => 'suggestions',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSuggestionFilters(): array
    {
        $scoreMax = $this->suggestionScoreMax;
        if ($scoreMax === '' || $scoreMax === null) {
            $scoreMax = null;
        } else {
            $scoreMax = (int) $scoreMax;
        }

        $issueKey = trim((string) $this->suggestionIssueKey);
        $action = trim((string) $this->suggestedAction);
        $state = $this->normalizedSuggestionStateFilter();
        $termId = $this->suggestionTermId;
        if ($termId === '' || $termId === null) {
            $termId = null;
        } else {
            $termId = (int) $termId;
        }

        return SeoAuditSuggestionFilterSet::normalize([
            'search' => trim((string) $this->suggestionSearch),
            'score_max' => $scoreMax,
            'issue_keys' => $issueKey !== '' ? [$issueKey] : [],
            'suggested_action' => $action,
            'state' => $state,
            'only_with_issues' => true,
            'language_scope' => $this->normalizedSuggestionLanguageScope(),
            'post_type_mode' => $this->suggestionPostTypeMode,
            'post_type' => $this->suggestionPostType,
            'taxonomy' => $this->suggestionTaxonomy,
            'term_id' => $termId,
            'exclude_taxonomy_archives' => true,
            'exclude_skip_seo_audit' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applySuggestionFilters(array $filters): void
    {
        $n = SeoAuditSuggestionFilterSet::normalize($filters);
        $this->suggestionSearch = (string) ($n['search'] ?? '');
        $this->suggestionSearchInput = $this->suggestionSearch;
        $this->suggestionScoreMax = $n['score_max'];
        $this->suggestionIssueKey = (string) (($n['issue_keys'][0] ?? '') ?: '');
        $this->suggestedAction = (string) ($n['suggested_action'] ?? '');
        $this->suggestionStateFilter = (string) ($n['state'] ?? 'available');
        $this->suggestionLanguageScope = (string) ($n['language_scope'] ?? 'primary');
        $this->suggestionPostTypeMode = (string) ($n['post_type_mode'] ?? SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL_EXCEPT_PAGE);
        $this->suggestionPostType = (string) ($n['post_type'] ?? '');
        $this->suggestionTaxonomy = (string) ($n['taxonomy'] ?? '');
        $this->suggestionTermId = $n['term_id'] ?? null;
        $this->normalizeSuggestionStateFilter();
        $this->resetSuggestionsPage();
    }

    protected function hydrateSuggestionFiltersFromHistory(): void
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            return;
        }

        $snapshot = app(ContentProjectPlannerRunService::class)->latestConfigurationSnapshot(
            $project,
            SeoContentProjectPlannerRun::SOURCE_SEO_AUDIT,
        );
        if (! is_array($snapshot)) {
            return;
        }

        $this->applySuggestionFilters(SeoAuditSuggestionFilterSet::fromSnapshot($snapshot));
    }

    /**
     * @return array{primary_configured: bool, primary_language_label: ?string, domain_edit_url: ?string}
     */
    protected function primaryLanguagePayloadForProject(SeoProject $project): array
    {
        $site = $project->site;
        if (! $site instanceof Site) {
            $siteId = (int) ($project->site_id ?? 0);
            $site = $siteId > 0 ? Site::query()->find($siteId) : null;
        }

        if (! $site instanceof Site) {
            return [
                'primary_configured' => false,
                'primary_language_label' => null,
                'domain_edit_url' => null,
            ];
        }

        $svc = app(SitePrimaryLanguageService::class);
        $resolved = $svc->resolvePrimaryLanguage($site);
        $label = $svc->primaryLanguageLabel($site, $resolved);

        return [
            'primary_configured' => $resolved !== null,
            'primary_language_label' => $label,
            'domain_edit_url' => DomainResource::getUrl('edit', ['record' => $site]),
        ];
    }

    protected function normalizedSuggestionLanguageScope(): string
    {
        $scope = strtolower(trim((string) $this->suggestionLanguageScope));

        return in_array($scope, ['primary', 'all'], true) ? $scope : 'primary';
    }

    protected function resetSuggestionsPage(): void
    {
        $this->resetPage('suggestionsPage');
        $this->clearSuggestionSelection();
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    protected function normalizeSuggestionIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    protected function notifySuggestionsNotDraft(): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.projects.suggestions_draft_required_title'))
            ->body(__('seo-content-ai::filament.projects.suggestions_draft_required_body'))
            ->warning()
            ->send();
    }

    protected function notifyPlannerProjectRequired(): void
    {
        Notification::make()
            ->title(__('seo-content-ai::filament.projects.seo_audit_draft_required_title'))
            ->body(__('seo-content-ai::filament.projects.seo_audit_draft_required_body'))
            ->warning()
            ->send();
    }

    protected function normalizedSuggestionStateFilter(): string
    {
        $state = strtolower(trim((string) $this->suggestionStateFilter));

        return in_array($state, ['available', 'dismissed', 'planned'], true)
            ? $state
            : 'available';
    }

    protected function normalizeSuggestionStateFilter(): void
    {
        $this->suggestionStateFilter = $this->normalizedSuggestionStateFilter();
        $this->showDismissed = $this->suggestionStateFilter === 'dismissed';
        $this->showPlanned = $this->suggestionStateFilter === 'planned';
    }

    protected function dispatchSuggestionCommand(object $command): ?ContentProjectActionResult
    {
        $project = $this->resolvePlannerProject();
        if (! $project instanceof SeoProject) {
            $this->notifyPlannerProjectRequired();

            return null;
        }

        try {
            $result = app(ContentProjectCommandBus::class)->dispatch(
                $command,
                ActorContext::user(
                    auth()->id() !== null ? (int) auth()->id() : null,
                    (int) ($project->site_id ?? 0) ?: null,
                ),
            );
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'content_project.suggestions.command',
                'project_id' => (int) $project->getKey(),
                'command' => $command::class,
            ]);
            Notification::make()
                ->title('Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $this->suggestionsLastResult = (string) $result->message;

        Notification::make()
            ->title($result->success
                ? __('seo-content-ai::filament.projects.suggestions_action_done')
                : 'Failed')
            ->body($result->message)
            ->{$result->success ? 'success' : 'danger'}()
            ->send();

        return $result;
    }
}
