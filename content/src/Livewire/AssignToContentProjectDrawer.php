<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Livewire;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticlePendingInternalLinkService;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

/**
 * Canonical Assign-to-Content-Project sidebar drawer.
 *
 * Modes route to existing domain backends; this component owns UI only.
 * Mount once at the panel shell; all callers open via assign-content-project:open.
 */
class AssignToContentProjectDrawer extends Component
{
    public bool $open = false;

    public bool $preparing = false;

    /** Monotonic token so overlapping prepare() calls drop stale completions. */
    public int $prepareRequestId = 0;

    public bool $submitting = false;

    public bool $quickCreateOpen = false;

    public bool $quickCreateSubmitting = false;

    public string $mode = AssignToContentProjectContract::MODE_ARTICLE;

    public string $source = '';

    /** @var list<int> */
    public array $articleIds = [];

    /** @var list<int> */
    public array $keywordIds = [];

    /** @var list<int> */
    public array $siteIds = [];

    public ?int $mapId = null;

    public ?string $anchorPhrase = null;

    /** @var list<array{keyword: string, title: string, source: string, source_article_id: int|null}> */
    public array $items = [];

    /** @var list<array{keyword: string, status: string, label: string}> */
    public array $itemStatuses = [];

    public ?int $projectId = null;

    /** @var array<string, int|null> siteId => projectId */
    public array $projectIdBySite = [];

    public string $type = SeoProjectTask::TYPE_REWRITE;

    public string $rewriteNotes = '';

    public string $focusKeyword = '';

    public string $keywordOverride = '';

    public string $titleOverride = '';

    public ?int $quickWriterId = null;

    public bool $needsFocusKeyword = false;

    public bool $ignoreMonthlyCapacity = false;

    public bool $showQuickCreate = false;

    public bool $showArticleFields = false;

    public bool $showMultiSite = false;

    public bool $showFocusKeyword = false;

    public bool $showKeywordOverride = false;

    public bool $showTitleOverride = false;

    /** @var array<int|string, string> */
    public array $projectOptions = [];

    /** @var array<int|string, string> */
    public array $siteOptions = [];

    /** @var array<int, array<int|string, string>> */
    public array $projectOptionsBySite = [];

    /** @var array<string, string> */
    public array $typeOptions = [];

    public ?string $errorMessage = null;

    public function prepare(array $detail): void
    {
        $incomingRequestId = (int) ($detail['_request_id'] ?? 0);
        if ($incomingRequestId > 0) {
            $this->prepareRequestId = $incomingRequestId;
        } else {
            $this->prepareRequestId++;
        }
        $requestId = $this->prepareRequestId;

        $payload = AssignToContentProjectContract::normalizePayload($detail);
        $this->resetFormState();

        $this->open = true;
        $this->preparing = true;
        $this->mode = $payload['mode'];
        $this->source = $payload['source'];
        $this->articleIds = $payload['article_ids'];
        $this->keywordIds = $payload['keyword_ids'];
        $this->siteIds = $payload['site_ids'];
        $this->mapId = $payload['map_id'];
        $this->anchorPhrase = $payload['anchor_phrase'];
        $this->items = $payload['items'];

        $defaults = $payload['defaults'];
        $options = $payload['options'];

        $this->ignoreMonthlyCapacity = (bool) ($options['ignore_monthly_capacity'] ?? false);
        $this->showQuickCreate = (bool) ($options['show_quick_create'] ?? false);
        $this->showArticleFields = (bool) ($options['show_article_fields'] ?? ($this->mode === AssignToContentProjectContract::MODE_ARTICLE));
        $this->showMultiSite = (bool) ($options['show_multi_site'] ?? ($this->mode === AssignToContentProjectContract::MODE_KEYWORD));
        $this->showFocusKeyword = (bool) ($options['show_focus_keyword'] ?? false);
        $this->showKeywordOverride = (bool) ($options['show_keyword_override'] ?? false);
        $this->showTitleOverride = (bool) ($options['show_title_override'] ?? false);

        $this->type = SeoProjectTask::normalizeType($defaults['type'] ?? (
            $this->mode === AssignToContentProjectContract::MODE_VOCABULARY_ITEMS
                ? SeoProjectTask::TYPE_CREATE
                : SeoProjectTask::TYPE_REWRITE
        ));
        $this->rewriteNotes = trim((string) ($defaults['rewrite_notes'] ?? ''));
        $this->focusKeyword = trim((string) ($defaults['focus_keyword'] ?? ''));
        $this->keywordOverride = trim((string) ($defaults['keyword'] ?? ''));
        $this->titleOverride = trim((string) ($defaults['title'] ?? ''));
        $this->typeOptions = SeoProjectTask::typeOptions();

        if ($this->mode === AssignToContentProjectContract::MODE_KEYWORD) {
            $this->prepareKeywordContext($defaults);
        } elseif ($this->mode === AssignToContentProjectContract::MODE_PENDING_LINK) {
            $this->preparePendingLinkContext($defaults);
        } elseif ($this->mode === AssignToContentProjectContract::MODE_VOCABULARY_ITEMS) {
            $this->prepareVocabularyItemsContext($defaults, $options);
        } else {
            $this->prepareArticleContext($defaults, $options);
        }

        if ($requestId !== $this->prepareRequestId) {
            return;
        }

        // User may have closed the Alpine shell while this request was in flight.
        if (! $this->open) {
            $this->preparing = false;

            return;
        }

        $this->preparing = false;
    }

    public function close(): void
    {
        if ($this->submitting || $this->quickCreateSubmitting) {
            return;
        }

        // Invalidate any in-flight prepare() so stale completions cannot reopen state.
        $this->prepareRequestId++;
        $this->open = false;
        $this->preparing = false;
        $this->quickCreateOpen = false;
        $this->resetFormState();
        $this->dispatch(AssignToContentProjectContract::CLOSE_EVENT);
        $this->js(
            'window.dispatchEvent(new CustomEvent('
            .json_encode(AssignToContentProjectContract::CLOSE_EVENT)
            .'));'
        );
    }

    public function submit(): void
    {
        if ($this->submitting) {
            return;
        }

        if ($this->preparing) {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body(__('seo-content-ai::filament.rank_group.loading'))
                ->warning()
                ->send();

            return;
        }

        $this->errorMessage = null;
        $this->submitting = true;

        try {
            match ($this->mode) {
                AssignToContentProjectContract::MODE_KEYWORD => $this->submitKeyword(),
                AssignToContentProjectContract::MODE_PENDING_LINK => $this->submitPendingLink(),
                AssignToContentProjectContract::MODE_VOCABULARY_ITEMS => $this->submitVocabularyItems(),
                default => $this->submitArticle(),
            };
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage();
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.assign_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->submitting = false;
        }
    }

    public function updatedProjectId(mixed $value): void
    {
        if ($this->mode === AssignToContentProjectContract::MODE_VOCABULARY_ITEMS) {
            $this->refreshVocabularyItemStatuses();
        }
    }

    public function updatedSiteIds(mixed $value): void
    {
        if ($this->mode !== AssignToContentProjectContract::MODE_KEYWORD) {
            return;
        }

        $this->ensureKeywordProjectOptionsForSites(
            array_values(array_filter(array_map('intval', is_array($value) ? $value : $this->siteIds))),
        );
    }

    public function quickCreate(?int $writerId = null): void
    {
        if ($this->quickCreateSubmitting) {
            return;
        }

        $siteId = (int) ($this->siteIds[0] ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_content_project_failed'))
                ->body(__('seo-content-ai::filament.article_list.assign_projects_mixed_domains'))
                ->danger()
                ->send();

            return;
        }

        $this->quickCreateSubmitting = true;

        try {
            $project = ArticleResource::quickCreateContentProject($siteId, $writerId ?? $this->quickWriterId);
            $this->projectOptions = ArticleResource::contentProjectOptions($siteId);
            $this->projectId = (int) $project->getKey();
            $this->quickCreateOpen = false;

            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_content_project_success'))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_content_project_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->quickCreateSubmitting = false;
        }
    }

    /**
     * @return array{label: string, selected_count: int, can_submit: bool, writer_options: array<int|string, string>}
     */
    public function getUiStateProperty(): array
    {
        return [
            'label' => AssignToContentProjectContract::label(),
            'selected_count' => $this->selectedCount(),
            'can_submit' => $this->canSubmit(),
            'writer_options' => $this->showQuickCreate ? SeoProjectResource::userSelectOptions() : [],
        ];
    }

    public function render()
    {
        return view('content::livewire.assign-to-content-project-drawer');
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $options
     */
    private function prepareArticleContext(array $defaults, array $options): void
    {
        $articles = $this->loadArticles($this->articleIds);
        if ($articles->isEmpty()) {
            $this->errorMessage = __('seo-content-ai::filament.keyword.workspace_article_not_found');

            return;
        }

        $siteId = (int) ($this->siteIds[0] ?? 0);
        if ($siteId <= 0) {
            $siteIds = $articles
                ->map(static fn (SeoArticle $article): ?int => ArticleResource::resolveArticleSiteId($article))
                ->filter(static fn (?int $id): bool => $id !== null && $id > 0)
                ->unique()
                ->values();
            $siteId = $siteIds->count() === 1 ? (int) $siteIds->first() : 0;
            if ($siteId > 0) {
                $this->siteIds = [$siteId];
            }
        }

        $this->projectOptions = $siteId > 0 ? ArticleResource::contentProjectOptions($siteId) : [];
        $directProjectId = $siteId > 0 ? ArticleResource::resolveDirectAssignContentProjectId($siteId) : null;
        $defaultProjectId = (int) ($defaults['project_id'] ?? 0);
        $this->projectId = $defaultProjectId > 0
            ? $defaultProjectId
            : ($directProjectId !== null && $directProjectId > 0 ? $directProjectId : null);

        $this->showQuickCreate = (bool) ($options['show_quick_create'] ?? true);
        $this->showArticleFields = (bool) ($options['show_article_fields'] ?? true);
        $this->showKeywordOverride = (bool) ($options['show_keyword_override'] ?? true);
        $this->showTitleOverride = (bool) ($options['show_title_override'] ?? true);
        $this->showFocusKeyword = (bool) ($options['show_focus_keyword'] ?? false);

        if ($this->showFocusKeyword || (bool) ($options['detect_missing_focus_keyword'] ?? false)) {
            $analyzer = app(SeoAnalyzerService::class);
            $missing = $articles->filter(static function (SeoArticle $article) use ($analyzer): bool {
                return trim((string) ($analyzer->resolveFocusKeywordForArticle($article) ?? '')) === '';
            });
            // Shared override applies to every selected article missing a focus keyword.
            $this->needsFocusKeyword = $missing->count() >= 1;
            $this->showFocusKeyword = $this->needsFocusKeyword;
        }

        if (SeoAccessControl::isContentManager()) {
            $this->quickWriterId = (int) (auth()->id() ?? 0) ?: null;
        }
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function prepareKeywordContext(array $defaults): void
    {
        $this->showArticleFields = false;
        $this->showQuickCreate = false;
        $this->showMultiSite = true;
        $this->siteOptions = KeywordResource::siteSelectOptions();

        if ($this->siteIds === []) {
            $global = SeoAccessControl::globalSiteId();
            if ($global !== null && (int) $global > 0) {
                $this->siteIds = [(int) $global];
            }
        }

        $this->projectOptionsBySite = [];
        $this->projectIdBySite = [];

        // Only hydrate project selects for sites the user currently has selected —
        // loading every domain's projects on open was a common slow path.
        $sitesToLoad = $this->siteIds !== []
            ? $this->siteIds
            : (array_keys($this->siteOptions) !== []
                ? [(int) array_key_first($this->siteOptions)]
                : []);

        $this->ensureKeywordProjectOptionsForSites($sitesToLoad, $defaults);

        $direct = KeywordResource::resolveKeywordDirectAssignData(
            $this->siteIds[0] ?? null,
        );
        if (is_array($direct)) {
            foreach ($direct as $key => $value) {
                if (str_starts_with((string) $key, 'project_id_') && is_numeric($value)) {
                    $siteId = (int) substr((string) $key, strlen('project_id_'));
                    if ($siteId > 0) {
                        $this->ensureKeywordProjectOptionsForSites([$siteId], $defaults);
                        $this->projectIdBySite[$siteId] = (int) $value;
                    }
                }
            }
            if (isset($direct['site_ids']) && is_array($direct['site_ids'])) {
                $this->siteIds = array_values(array_filter(array_map('intval', $direct['site_ids'])));
                $this->ensureKeywordProjectOptionsForSites($this->siteIds, $defaults);
            }
        }
    }

    /**
     * @param  list<int>  $siteIds
     * @param  array<string, mixed>  $defaults
     */
    private function ensureKeywordProjectOptionsForSites(array $siteIds, array $defaults = []): void
    {
        foreach ($siteIds as $siteId) {
            $siteId = (int) $siteId;
            if ($siteId <= 0) {
                continue;
            }

            if (! isset($this->projectOptionsBySite[$siteId])) {
                $this->projectOptionsBySite[$siteId] = ArticleResource::contentProjectOptions($siteId);
            }

            if (array_key_exists($siteId, $this->projectIdBySite) && $this->projectIdBySite[$siteId] !== null) {
                continue;
            }

            $defaultKey = 'project_id_'.$siteId;
            $defaultProject = (int) ($defaults[$defaultKey] ?? 0);
            if ($defaultProject <= 0) {
                $direct = ArticleResource::resolveDirectAssignContentProjectId($siteId);
                $defaultProject = $direct !== null ? (int) $direct : 0;
            }
            $this->projectIdBySite[$siteId] = $defaultProject > 0 ? $defaultProject : null;
        }
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function preparePendingLinkContext(array $defaults): void
    {
        $this->showArticleFields = false;
        $this->showMultiSite = false;
        $this->showQuickCreate = false;
        $this->showFocusKeyword = false;

        $articleId = (int) ($this->articleIds[0] ?? 0);
        $article = $articleId > 0 ? $this->loadArticles([$articleId])->first() : null;
        $siteId = (int) ($this->siteIds[0] ?? ($article?->site_id ?? 0));
        if ($siteId > 0) {
            $this->siteIds = [$siteId];
        }

        $this->projectOptions = $siteId > 0 ? ArticleResource::contentProjectOptions($siteId) : [];
        $direct = $siteId > 0 ? ArticleResource::resolveDirectAssignContentProjectId($siteId) : null;
        $defaultProjectId = (int) ($defaults['project_id'] ?? 0);
        $this->projectId = $defaultProjectId > 0
            ? $defaultProjectId
            : ($direct !== null && $direct > 0 ? $direct : null);

        if ($this->anchorPhrase === null || trim($this->anchorPhrase) === '') {
            $this->errorMessage = __('seo-content-ai::filament.article_edit.pending_link_empty_phrase');
        }
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $options
     */
    private function prepareVocabularyItemsContext(array $defaults, array $options): void
    {
        $this->showArticleFields = false;
        $this->showMultiSite = false;
        $this->showFocusKeyword = false;
        $this->showKeywordOverride = false;
        $this->showTitleOverride = false;
        $this->showQuickCreate = (bool) ($options['show_quick_create'] ?? true);
        $this->type = SeoProjectTask::TYPE_CREATE;

        $siteId = (int) ($this->siteIds[0] ?? 0);
        if ($siteId <= 0 && $this->articleIds !== []) {
            $article = $this->loadArticles([$this->articleIds[0]])->first();
            if ($article instanceof SeoArticle) {
                $resolved = ArticleResource::resolveArticleSiteId($article);
                if ($resolved !== null && $resolved > 0) {
                    $siteId = $resolved;
                    $this->siteIds = [$siteId];
                }
            }
        }

        if ($this->items === []) {
            $this->errorMessage = __('seo-content-ai::filament.articles_optimal.assign_failed');

            return;
        }

        $this->projectOptions = $siteId > 0 ? ArticleResource::contentProjectOptions($siteId) : [];
        $directProjectId = $siteId > 0 ? ArticleResource::resolveDirectAssignContentProjectId($siteId) : null;
        $defaultProjectId = (int) ($defaults['project_id'] ?? 0);
        $this->projectId = $defaultProjectId > 0
            ? $defaultProjectId
            : ($directProjectId !== null && $directProjectId > 0 ? $directProjectId : null);

        $this->refreshVocabularyItemStatuses();

        if (SeoAccessControl::isContentManager()) {
            $this->quickWriterId = (int) (auth()->id() ?? 0) ?: null;
        }
    }

    private function refreshVocabularyItemStatuses(): void
    {
        $projectId = (int) ($this->projectId ?? 0);
        $siteId = (int) ($this->siteIds[0] ?? 0);
        $this->itemStatuses = [];

        if ($projectId <= 0 || $siteId <= 0 || $this->items === []) {
            foreach ($this->items as $item) {
                $this->itemStatuses[] = [
                    'keyword' => $item['keyword'],
                    'status' => 'new',
                    'label' => 'New',
                ];
            }

            return;
        }

        $preview = app(KeywordProjectAssignmentService::class)->assignPhrases(
            array_map(static fn (array $item): string => $item['keyword'], $this->items),
            $projectId,
            $siteId,
            dryRun: true,
        );

        // Dry-run returns aggregate counts; build per-item statuses via exact lookup.
        foreach ($this->items as $item) {
            $phrase = $item['keyword'];
            $needle = mb_strtolower(preg_replace('/\s+/u', ' ', trim($phrase)) ?? trim($phrase));
            $inProject = SeoProjectTask::query()
                ->where('type', SeoProjectTask::TYPE_CREATE)
                ->where('site_id', $siteId)
                ->where(function ($query) use ($needle): void {
                    $query->whereRaw('LOWER(TRIM(keyword)) = ?', [$needle])
                        ->orWhereRaw('LOWER(TRIM(source_content)) = ?', [$needle])
                        ->orWhereRaw('LOWER(TRIM(title)) = ?', [$needle]);
                })
                ->exists();
            $hasArticle = SeoArticle::query()
                ->where('site_id', $siteId)
                ->where(function ($query) use ($needle): void {
                    $query->whereRaw('LOWER(TRIM(title)) = ?', [$needle])
                        ->orWhereHas('articleMetas', static function ($meta) use ($needle): void {
                            $meta->where('meta_key', 'seo_focus_keyword')
                                ->whereRaw('LOWER(TRIM(meta_value)) = ?', [$needle]);
                        });
                })
                ->exists();

            if ($inProject) {
                $status = 'already_in_project';
                $label = 'Already in project';
            } elseif ($hasArticle) {
                $status = 'existing_article';
                $label = 'Existing article';
            } else {
                $status = 'new';
                $label = 'New';
            }

            $this->itemStatuses[] = [
                'keyword' => $phrase,
                'status' => $status,
                'label' => $label,
            ];
        }

        unset($preview);
    }

    private function submitArticle(): void
    {
        $projectId = (int) ($this->projectId ?? 0);
        if ($projectId <= 0 || ! SeoProject::query()->whereKey($projectId)->exists()) {
            $this->errorMessage = __('seo-content-ai::filament.articles_optimal.assign_no_project');
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body($this->errorMessage)
                ->warning()
                ->send();

            return;
        }

        $articles = $this->loadArticles($this->articleIds);
        if ($articles->isEmpty()) {
            $this->errorMessage = __('seo-content-ai::filament.keyword.workspace_article_not_found');
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body($this->errorMessage)
                ->warning()
                ->send();

            return;
        }

        $focusKeywordInput = trim($this->focusKeyword);
        if ($this->needsFocusKeyword && $focusKeywordInput === '') {
            $this->errorMessage = __('seo-content-ai::filament.articles_optimal.assign_missing_keyword_required');
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body($this->errorMessage)
                ->warning()
                ->send();

            return;
        }

        if ($this->needsFocusKeyword && $focusKeywordInput !== '') {
            $analyzer = app(SeoAnalyzerService::class);
            $userId = (int) (auth()->id() ?? 0);
            foreach ($articles as $article) {
                if (trim((string) ($analyzer->resolveFocusKeywordForArticle($article) ?? '')) !== '') {
                    continue;
                }
                $siteId = (int) ($article->site_id ?? 0);
                if ($siteId <= 0) {
                    continue;
                }
                KeywordFocusAttach::syncMainKeyword($article, $siteId, $userId, $focusKeywordInput);
                $article->unsetRelation('articleMetas');
            }
        }

        $data = [
            'type' => $this->type,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_CONTENT,
            'rewrite_notes' => $this->rewriteNotes,
            'focus_keyword' => $focusKeywordInput !== '' ? $focusKeywordInput : null,
            'keyword' => $this->keywordOverride !== '' ? $this->keywordOverride : null,
            'title' => $this->titleOverride !== '' ? $this->titleOverride : null,
            'ignore_monthly_capacity' => $this->ignoreMonthlyCapacity,
        ];

        $summary = ArticleResource::assignArticlesFromFormData($articles, $projectId, $data);

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.assign_completed'))
            ->body(ArticleResource::buildAssignContentProjectBody($summary))
            ->success()
            ->send();

        if ($this->ignoreMonthlyCapacity) {
            $project = SeoProject::query()->find($projectId);
            $remaining = $project?->remainingTaskCapacity() ?? 0;
            if ($project instanceof SeoProject && $remaining <= 2) {
                Notification::make()
                    ->title($remaining === 0
                        ? __('seo-content-ai::filament.articles_optimal.project_capacity_full')
                        : __('seo-content-ai::filament.articles_optimal.project_capacity_near'))
                    ->body(__('seo-content-ai::filament.articles_optimal.project_capacity_remaining', [
                        'count' => $remaining,
                    ]))
                    ->warning()
                    ->send();
            }
        }

        $this->emitSuccess([
            'mode' => $this->mode,
            'source' => $this->source,
            'article_ids' => $this->articleIds,
            'project_id' => $projectId,
            'summary' => $summary,
        ]);
        $this->open = false;
        $this->resetFormState();
    }

    private function submitKeyword(): void
    {
        $keywords = Keyword::query()->whereIn('id', $this->keywordIds)->get();
        if ($keywords->isEmpty()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_map_not_found'))
                ->danger()
                ->send();

            return;
        }

        foreach ($keywords as $keyword) {
            if (! KeywordResource::canAssignKeywordToContentProject($keyword)) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.workspace_assign_denied'))
                    ->warning()
                    ->send();

                return;
            }
        }

        $assignData = [
            'site_ids' => $this->siteIds,
        ];
        foreach ($this->siteIds as $siteId) {
            $assignData['project_id_'.$siteId] = (int) ($this->projectIdBySite[$siteId] ?? 0);
        }

        $summary = KeywordResource::executeAssignKeywordsToContentProjects($keywords, $assignData);

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.assign_completed'))
            ->body(ArticleResource::buildAssignContentProjectBody($summary))
            ->success()
            ->send();

        $this->emitSuccess([
            'mode' => $this->mode,
            'source' => $this->source,
            'keyword_ids' => $this->keywordIds,
            'summary' => $summary,
        ]);
        $this->open = false;
        $this->resetFormState();
    }

    private function submitPendingLink(): void
    {
        $articleId = (int) ($this->articleIds[0] ?? 0);
        $phrase = trim((string) ($this->anchorPhrase ?? ''));
        $projectId = (int) ($this->projectId ?? 0);

        $article = $articleId > 0
            ? $this->loadArticles([$articleId])->first()
            : null;

        if (! $article instanceof SeoArticle || $phrase === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.pending_link_empty_phrase'))
                ->warning()
                ->send();

            return;
        }

        if ($projectId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.pending_link_no_content_project'))
                ->warning()
                ->send();

            return;
        }

        $result = app(ArticlePendingInternalLinkService::class)->assignFromEditor(
            $article,
            $phrase,
            $projectId,
        );

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.assign_failed'))
                ->body((string) ($result['message'] ?? __('seo-content-ai::filament.keyword.workspace_assign_denied')))
                ->danger()
                ->send();

            return;
        }

        if (! ($result['assigned_to_project'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.pending_link_created'))
                ->body((string) ($result['message'] ?? ''))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.assign_completed'))
                ->body((string) ($result['message'] ?? ''))
                ->success()
                ->send();
        }

        $this->dispatch(
            'pending-internal-link-ready',
            placeholderHref: (string) ($result['placeholder_href'] ?? ''),
            message: (string) ($result['message'] ?? ''),
        );

        $this->js(
            'window.dispatchEvent(new CustomEvent("pending-internal-link-ready",{detail:'
            .json_encode([
                'placeholderHref' => (string) ($result['placeholder_href'] ?? ''),
                'placeholder_href' => (string) ($result['placeholder_href'] ?? ''),
                'message' => (string) ($result['message'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .'}));'
        );

        $this->emitSuccess([
            'mode' => $this->mode,
            'source' => $this->source,
            'article_ids' => $this->articleIds,
            'project_id' => $projectId,
            'result' => $result,
        ]);
        $this->open = false;
        $this->resetFormState();
    }

    private function submitVocabularyItems(): void
    {
        $projectId = (int) ($this->projectId ?? 0);
        $siteId = (int) ($this->siteIds[0] ?? 0);
        if ($projectId <= 0 || ! SeoProject::query()->whereKey($projectId)->exists()) {
            $this->errorMessage = __('seo-content-ai::filament.articles_optimal.assign_no_project');
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body($this->errorMessage)
                ->warning()
                ->send();

            return;
        }

        if ($siteId <= 0 || $this->items === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.assign_failed'))
                ->warning()
                ->send();

            return;
        }

        $phrases = array_values(array_map(
            static fn (array $item): string => $item['keyword'],
            $this->items,
        ));

        $summary = app(KeywordProjectAssignmentService::class)->assignPhrases(
            $phrases,
            $projectId,
            $siteId,
        );

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.assign_completed'))
            ->body(ArticleResource::buildAssignContentProjectBody($summary))
            ->success()
            ->send();

        $this->emitSuccess([
            'mode' => $this->mode,
            'source' => $this->source,
            'items' => $this->items,
            'project_id' => $projectId,
            'summary' => $summary,
        ]);
        $this->open = false;
        $this->resetFormState();
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function emitSuccess(array $detail): void
    {
        $this->js(
            'window.dispatchEvent(new CustomEvent('
            .json_encode(AssignToContentProjectContract::SUCCESS_EVENT)
            .',{detail:'
            .json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .'}));'
        );
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, SeoArticle>
     */
    private function loadArticles(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        // Explicit IDs must resolve across global-site UI filter (Article Editor can open
        // articles outside the currently selected domain).
        return ArticleResource::getRecordRouteBindingEloquentQuery()
            ->whereIn('id', $ids)
            ->with(['articleMetas' => static function ($relation): void {
                $relation->where('meta_key', 'seo_focus_keyword');
            }])
            ->get();
    }

    private function selectedCount(): int
    {
        return match ($this->mode) {
            AssignToContentProjectContract::MODE_KEYWORD => count($this->keywordIds),
            AssignToContentProjectContract::MODE_VOCABULARY_ITEMS => count($this->items),
            default => count($this->articleIds),
        };
    }

    private function canSubmit(): bool
    {
        if ($this->submitting || $this->preparing) {
            return false;
        }

        if ($this->mode === AssignToContentProjectContract::MODE_KEYWORD) {
            if ($this->siteIds === [] || $this->keywordIds === []) {
                return false;
            }

            foreach ($this->siteIds as $siteId) {
                if ((int) ($this->projectIdBySite[$siteId] ?? 0) <= 0) {
                    return false;
                }
            }

            return true;
        }

        if ($this->mode === AssignToContentProjectContract::MODE_PENDING_LINK) {
            return (int) ($this->projectId ?? 0) > 0
                && trim((string) ($this->anchorPhrase ?? '')) !== '';
        }

        if ($this->mode === AssignToContentProjectContract::MODE_VOCABULARY_ITEMS) {
            return (int) ($this->projectId ?? 0) > 0
                && $this->items !== []
                && (int) ($this->siteIds[0] ?? 0) > 0
                && $this->errorMessage === null;
        }

        if ((int) ($this->projectId ?? 0) <= 0 || $this->articleIds === []) {
            return false;
        }

        if ($this->needsFocusKeyword && trim($this->focusKeyword) === '') {
            return false;
        }

        return $this->errorMessage === null || $this->needsFocusKeyword;
    }

    private function resetFormState(): void
    {
        $this->mode = AssignToContentProjectContract::MODE_ARTICLE;
        $this->source = '';
        $this->articleIds = [];
        $this->keywordIds = [];
        $this->siteIds = [];
        $this->mapId = null;
        $this->anchorPhrase = null;
        $this->items = [];
        $this->itemStatuses = [];
        $this->projectId = null;
        $this->projectIdBySite = [];
        $this->type = SeoProjectTask::TYPE_REWRITE;
        $this->rewriteNotes = '';
        $this->focusKeyword = '';
        $this->keywordOverride = '';
        $this->titleOverride = '';
        $this->quickWriterId = null;
        $this->needsFocusKeyword = false;
        $this->ignoreMonthlyCapacity = false;
        $this->showQuickCreate = false;
        $this->showArticleFields = false;
        $this->showMultiSite = false;
        $this->showFocusKeyword = false;
        $this->showKeywordOverride = false;
        $this->showTitleOverride = false;
        $this->projectOptions = [];
        $this->siteOptions = [];
        $this->projectOptionsBySite = [];
        $this->typeOptions = [];
        $this->errorMessage = null;
        $this->quickCreateOpen = false;
    }
}
