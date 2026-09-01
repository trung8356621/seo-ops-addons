<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Livewire;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

/**
 * Canonical Add-to-Draft sidebar (legacy Assign-to-Content-Project shell).
 *
 * Destination is always Shared Planning Draft via PlanningDraftIntakeService.
 * Mount once at the panel shell; callers open via assign-content-project:open.
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

    public bool $ignoreMonthlyCapacity = true;

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

        $this->ignoreMonthlyCapacity = true;
        $this->showQuickCreate = false;
        $this->showArticleFields = false;
        $this->showMultiSite = $this->mode === AssignToContentProjectContract::MODE_KEYWORD;
        $this->showFocusKeyword = false;
        $this->showKeywordOverride = false;
        $this->showTitleOverride = false;

        $this->type = SeoProjectTask::normalizeType($defaults['type'] ?? (
            $this->mode === AssignToContentProjectContract::MODE_VOCABULARY_ITEMS
                ? SeoProjectTask::TYPE_CREATE
                : SeoProjectTask::TYPE_REWRITE
        ));
        $this->rewriteNotes = trim((string) ($defaults['rewrite_notes'] ?? ''));
        $this->focusKeyword = trim((string) ($defaults['focus_keyword'] ?? ''));
        $this->keywordOverride = '';
        $this->titleOverride = '';
        $this->typeOptions = [];

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

        if (! $this->open) {
            $this->preparing = false;

            return;
        }

        $this->preparing = false;

        // Bulk / pending / vocab may still auto-submit after shell is open.
        // Single-article with keyword must use preflightOpen() — never reach here for that path.
        if ($this->shouldAutoSubmitAfterPrepare()) {
            $this->submit();
        }
    }

    /**
     * Preflight for Alpine OPEN_EVENT: handle single-article-with-keyword without opening shell.
     *
     * @param  array<string, mixed>  $detail
     * @return array{handled: bool, needs_keyword: bool, status: string|null}
     */
    public function preflightOpen(array $detail): array
    {
        $payload = AssignToContentProjectContract::normalizePayload($detail);

        if ($payload['mode'] !== AssignToContentProjectContract::MODE_ARTICLE) {
            return ['handled' => false, 'needs_keyword' => false, 'status' => null];
        }

        if (count($payload['article_ids']) !== 1) {
            return ['handled' => false, 'needs_keyword' => false, 'status' => null];
        }

        $articles = $this->loadArticles($payload['article_ids']);
        $article = $articles->first();
        if (! $article instanceof SeoArticle) {
            return ['handled' => false, 'needs_keyword' => false, 'status' => null];
        }

        $intake = app(PlanningDraftIntakeService::class);
        if ($intake->articleNeedsKeyword($article)) {
            return ['handled' => false, 'needs_keyword' => true, 'status' => null];
        }

        $result = $intake->addArticles($articles);
        $this->notifyIntakeResult($result);
        $this->emitSuccess([
            'mode' => AssignToContentProjectContract::MODE_ARTICLE,
            'source' => $payload['source'],
            'article_ids' => $payload['article_ids'],
            'draft_project_id' => $result->draftProjectId,
            'status' => $result->status,
            'summary' => $result->summary,
            'direct' => true,
        ]);

        return [
            'handled' => true,
            'needs_keyword' => false,
            'status' => $result->status,
        ];
    }

    public function close(): void
    {
        if ($this->submitting || $this->quickCreateSubmitting) {
            return;
        }

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
        unset($value);
        if ($this->mode === AssignToContentProjectContract::MODE_VOCABULARY_ITEMS) {
            $this->refreshVocabularyItemStatuses();
        }
    }

    public function updatedSiteIds(mixed $value): void
    {
        unset($value);
    }

    public function quickCreate(?int $writerId = null): void
    {
        unset($writerId);
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
            'writer_options' => [],
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
        unset($defaults);
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
            $siteId = $siteIds->count() === 1
                ? (int) $siteIds->first()
                : (int) ($articles->first()?->site_id ?? 0);
            if ($siteId > 0) {
                $this->siteIds = [$siteId];
            }
        }

        $this->projectId = null;
        $this->projectOptions = [];
        $this->showQuickCreate = false;
        $this->showArticleFields = false;
        $this->showKeywordOverride = false;
        $this->showTitleOverride = false;

        $intake = app(PlanningDraftIntakeService::class);
        $this->needsFocusKeyword = $intake->anyArticleNeedsKeyword($articles);
        $this->showFocusKeyword = $this->needsFocusKeyword
            || (bool) ($options['show_focus_keyword'] ?? false)
            || (bool) ($options['detect_missing_focus_keyword'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function prepareKeywordContext(array $defaults): void
    {
        unset($defaults);
        $this->showArticleFields = false;
        $this->showQuickCreate = false;
        $this->showMultiSite = true;
        $this->siteOptions = KeywordResource::siteSelectOptions();
        $this->projectOptionsBySite = [];
        $this->projectIdBySite = [];
        $this->projectId = null;

        if ($this->siteIds === []) {
            $global = SeoAccessControl::globalSiteId();
            if ($global !== null && (int) $global > 0) {
                $this->siteIds = [(int) $global];
            } elseif (array_keys($this->siteOptions) !== []) {
                $this->siteIds = [(int) array_key_first($this->siteOptions)];
            }
        }
    }

    /**
     * @param  list<int>  $siteIds
     * @param  array<string, mixed>  $defaults
     */
    private function ensureKeywordProjectOptionsForSites(array $siteIds, array $defaults = []): void
    {
        unset($siteIds, $defaults);
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function preparePendingLinkContext(array $defaults): void
    {
        unset($defaults);
        $this->showArticleFields = false;
        $this->showMultiSite = false;
        $this->showQuickCreate = false;
        $this->showFocusKeyword = false;
        $this->projectOptions = [];
        $this->projectId = null;

        $articleId = (int) ($this->articleIds[0] ?? 0);
        $article = $articleId > 0 ? $this->loadArticles([$articleId])->first() : null;
        $siteId = (int) ($this->siteIds[0] ?? ($article?->site_id ?? 0));
        if ($siteId > 0) {
            $this->siteIds = [$siteId];
        }

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
        unset($defaults, $options);
        $this->showArticleFields = false;
        $this->showMultiSite = false;
        $this->showFocusKeyword = false;
        $this->showKeywordOverride = false;
        $this->showTitleOverride = false;
        $this->showQuickCreate = false;
        $this->type = SeoProjectTask::TYPE_CREATE;
        $this->projectOptions = [];
        $this->projectId = null;

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

        $this->refreshVocabularyItemStatuses();
    }

    private function refreshVocabularyItemStatuses(): void
    {
        $siteId = (int) ($this->siteIds[0] ?? 0);
        $this->itemStatuses = [];

        foreach ($this->items as $item) {
            $phrase = $item['keyword'];
            $needle = mb_strtolower(preg_replace('/\s+/u', ' ', trim($phrase)) ?? trim($phrase));
            $status = 'new';
            $label = 'New';

            if ($siteId > 0 && $needle !== '') {
                $inDraft = SeoProjectTask::query()
                    ->where('type', SeoProjectTask::TYPE_CREATE)
                    ->where('site_id', $siteId)
                    ->where(function ($query) use ($needle): void {
                        $query->whereRaw('LOWER(TRIM(keyword)) = ?', [$needle])
                            ->orWhereRaw('LOWER(TRIM(source_content)) = ?', [$needle])
                            ->orWhereRaw('LOWER(TRIM(title)) = ?', [$needle]);
                    })
                    ->whereHas('project', static function ($q): void {
                        $q->where('status', SeoProject::STATUS_DRAFT)
                            ->whereNull('archived_at');
                    })
                    ->exists();
                if ($inDraft) {
                    $status = 'already_in_project';
                    $label = 'In Draft';
                }
            }

            $this->itemStatuses[] = [
                'keyword' => $phrase,
                'status' => $status,
                'label' => $label,
            ];
        }
    }

    private function shouldAutoSubmitAfterPrepare(): bool
    {
        if ($this->errorMessage !== null && ! $this->needsFocusKeyword) {
            return false;
        }

        // Single article with keyword is handled by preflightOpen() — never flash shell.
        if ($this->mode === AssignToContentProjectContract::MODE_ARTICLE) {
            if (count($this->articleIds) === 1 && ! $this->needsFocusKeyword) {
                return false;
            }

            return ! $this->needsFocusKeyword && $this->articleIds !== [];
        }

        if ($this->mode === AssignToContentProjectContract::MODE_PENDING_LINK) {
            return trim((string) ($this->anchorPhrase ?? '')) !== '' && $this->articleIds !== [];
        }

        if ($this->mode === AssignToContentProjectContract::MODE_VOCABULARY_ITEMS) {
            return $this->items !== [] && (int) ($this->siteIds[0] ?? 0) > 0;
        }

        return false;
    }

    private function submitArticle(): void
    {
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

        $result = app(PlanningDraftIntakeService::class)->addArticles(
            $articles,
            trim($this->focusKeyword) !== '' ? trim($this->focusKeyword) : null,
        );

        $this->notifyIntakeResult($result);
        if (! $result->isSuccess()) {
            $this->errorMessage = $result->message;

            return;
        }

        $this->emitSuccess([
            'mode' => $this->mode,
            'source' => $this->source,
            'article_ids' => $this->articleIds,
            'draft_project_id' => $result->draftProjectId,
            'status' => $result->status,
            'summary' => $result->summary,
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

        $result = app(PlanningDraftIntakeService::class)->addKeywords($keywords, $this->siteIds);
        $this->notifyIntakeResult($result);
        if (! $result->isSuccess()) {
            return;
        }

        $this->emitSuccess([
            'mode' => $this->mode,
            'source' => $this->source,
            'keyword_ids' => $this->keywordIds,
            'draft_project_id' => $result->draftProjectId,
            'status' => $result->status,
            'summary' => $result->summary,
        ]);
        $this->open = false;
        $this->resetFormState();
    }

    private function submitPendingLink(): void
    {
        $articleId = (int) ($this->articleIds[0] ?? 0);
        $phrase = trim((string) ($this->anchorPhrase ?? ''));

        if ($articleId <= 0 || $phrase === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.pending_link_empty_phrase'))
                ->warning()
                ->send();

            return;
        }

        $result = app(PlanningDraftIntakeService::class)->addPendingLink($articleId, $phrase);
        $this->notifyIntakeResult($result);
        if (! $result->isSuccess()) {
            return;
        }

        $this->dispatch(
            'pending-internal-link-ready',
            placeholderHref: '',
            message: $result->message,
        );

        $this->js(
            'window.dispatchEvent(new CustomEvent("pending-internal-link-ready",{detail:'
            .json_encode([
                'message' => $result->message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .'}));'
        );

        $this->emitSuccess([
            'mode' => $this->mode,
            'source' => $this->source,
            'article_ids' => $this->articleIds,
            'draft_project_id' => $result->draftProjectId,
            'status' => $result->status,
            'summary' => $result->summary,
        ]);
        $this->open = false;
        $this->resetFormState();
    }

    private function submitVocabularyItems(): void
    {
        $siteId = (int) ($this->siteIds[0] ?? 0);
        if ($siteId <= 0 || $this->items === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.assign_failed'))
                ->warning()
                ->send();

            return;
        }

        $result = app(PlanningDraftIntakeService::class)->addVocabularyPhrases(
            $this->items,
            $siteId,
            (int) ($this->articleIds[0] ?? 0) ?: null,
        );

        $this->notifyIntakeResult($result);
        if (! $result->isSuccess()) {
            return;
        }

        $this->emitSuccess([
            'mode' => $this->mode,
            'source' => $this->source,
            'items' => $this->items,
            'draft_project_id' => $result->draftProjectId,
            'status' => $result->status,
            'summary' => $result->summary,
        ]);
        $this->open = false;
        $this->resetFormState();
    }

    private function notifyIntakeResult(PlanningDraftIntakeResult $result): void
    {
        if ($result->isAlreadyInDraft()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.already_in_draft'))
                ->body($result->message)
                ->info()
                ->send();

            return;
        }

        if ($result->isSuccess()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.add_to_draft_completed'))
                ->body($result->message)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
            ->body($result->message !== '' ? $result->message : __('seo-content-ai::filament.articles_optimal.assign_failed'))
            ->warning()
            ->send();
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
            return $this->siteIds !== [] && $this->keywordIds !== [];
        }

        if ($this->mode === AssignToContentProjectContract::MODE_PENDING_LINK) {
            return trim((string) ($this->anchorPhrase ?? '')) !== '' && $this->articleIds !== [];
        }

        if ($this->mode === AssignToContentProjectContract::MODE_VOCABULARY_ITEMS) {
            return $this->items !== []
                && (int) ($this->siteIds[0] ?? 0) > 0
                && $this->errorMessage === null;
        }

        if ($this->articleIds === []) {
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
        $this->ignoreMonthlyCapacity = true;
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
