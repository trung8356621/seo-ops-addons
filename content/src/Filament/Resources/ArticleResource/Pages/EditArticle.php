<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages;


use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\Content\Enums\ArticleReviewActionType;
use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Enums\ArticleWritingExecutionMode;
use Omnichannel\Addons\Content\Enums\ContentType;
use Omnichannel\Addons\AiPrompt\Enums\ArticleWritingPromptOwnerType;
use Omnichannel\Addons\Seo\Exceptions\FaqManualExtractException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsWorkflows;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Seo\Filament\Resources\Pages\SeoEditRecord;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleCtaPlaceholderService;
use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Omnichannel\Addons\Content\Services\ArticleEditorHtmlSanitizeService;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Content\Services\ArticleEditorPersistService;
use Omnichannel\Addons\Media\Services\ArticleEditorMediaAiService;
use Omnichannel\Addons\Content\Services\ArticleEditorReadinessService;
use Omnichannel\Addons\Content\Services\ArticleEditorSeoMetaService;
use Omnichannel\Addons\Content\Services\ArticleEditorSeoPayloadService;
use Omnichannel\Addons\Content\Services\ArticleFaqBodySyncService;
use Omnichannel\Addons\Content\Services\ArticleFaqEditorService;
use Omnichannel\Addons\Content\Services\ArticleFaqExtractDebugService;
use Omnichannel\Addons\Content\Services\ArticleFaqGeneratorService;
use Omnichannel\Addons\Content\Services\ArticleFaqManualExtractService;
use Omnichannel\Addons\WordPress\Services\ArticleFaqWordPressImportService;
use Omnichannel\Addons\WordPress\Services\ArticleFaqWordPressRestoreService;
use Omnichannel\Addons\Content\Services\ArticleFeaturedSnippetGeneratorService;
use Omnichannel\Addons\Seo\Services\ArticleGoogleSerpPreviewService;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSearchService;
use Omnichannel\Addons\SearchFoundation\Services\ArticleKeywordLinkReconcileService;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use Omnichannel\Addons\Content\Services\ArticlePendingInternalLinkService;
use Omnichannel\Addons\ContentProjects\Services\ArticlePipelineRerunService;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryApplicationService;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryPendingDraftStore;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\WordPress\Services\ArticlePolylangSyncService;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;
use Omnichannel\Addons\Content\Services\ArticleQuickPostReviewService;
use Omnichannel\Addons\Content\Services\ArticleQuickTranslateService;
use Omnichannel\Addons\Content\Services\ArticleReviewService;
use Omnichannel\Addons\Publishing\Services\ArticleScheduleReconcileService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\Media\Services\EditorImageTaskResolverService;
use Omnichannel\Addons\Content\Exceptions\ArticleReviewException;
use Omnichannel\Addons\Media\Services\MediaLibraryAccessScope;
use Omnichannel\Addons\Media\Services\MediaLibraryArticleResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptPostProcessingApplyService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Content\Services\SeoArticleRevisionService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Media\Services\SeoMediaLibraryService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\WordPress\Services\VirtualCommentService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Omnichannel\Addons\Media\Services\SeoMediaUrlReplacementService;
use Omnichannel\Addons\WordPress\Services\WordPressAttachmentMetaUpdateService;
use Omnichannel\Addons\WordPress\Services\WordPressAttachmentRenameService;
use Omnichannel\Addons\WordPress\Services\WordPressMediaLibraryService;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Content\Support\PublishCategoryOptionsAssembler;
use Omnichannel\Addons\Publishing\Support\PublishingTaxonomySelectionFilter;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionContext;
use Omnichannel\Addons\Content\Support\ArticleWritingExecutionResult;
use Omnichannel\Addons\Content\Support\ArticleWritingInput;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\SearchIntelligence\Support\RankMathSeoValueNormalizer;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Content\Support\ArticleEditorContentLifecycle;
use Omnichannel\Addons\Content\Support\ArticleEditorPerfDebug;
use Omnichannel\Addons\Content\Support\ArticleMetaMap;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\Content\Support\ArticleEditorSessionErrorCode;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\Seo\Support\SeoDisplayTimezone;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\WordPress\Support\WordPressImageUrl;
use App\Services\SeoEngineService;
use Filament\Actions;
use Filament\Notifications\Notification;
use App\Support\RuntimeLogger;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class EditArticle extends SeoEditRecord
{
    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.edit-article';

    public string $articleTitle = '';

    public string $articleSlug = '';

    /** Record thuộc domain khác global domain đang chọn (chỉ note UI, không chặn). */
    public bool $recordDomainDiffersFromGlobal = false;

    public string $seoTitle = '';

    public string $seoMetaDescription = '';

    public string $seoTitleHydrated = '';

    public string $seoMetaDescriptionHydrated = '';

    public string $focusKeyword = '';

    public string $articleStatus = 'draft';

    public string $visibility = 'public';

    public string $articlePostType = 'article';

    public string $publishDay = '';

    public string $publishMonth = '';

    public string $publishYear = '';

    public string $publishHour = '';

    public string $publishMinute = '';

    public ?string $featuredImageUrl = null;

    /** @var array<int, array{id: int, url: string}> */
    public array $productGallery = [];

    public bool $mediaPickerOpen = false;

    /** @var 'featured'|'gallery'|'editor-block' */
    public string $mediaPickerMode = 'featured';

    public ?string $mediaPickerTargetBlockId = null;

    public string $mediaPickerSearch = '';

    /** @var list<array<string, mixed>> */
    public array $mediaPickerImages = [];

    public int $mediaPickerPage = 1;

    public int $mediaPickerTotalPages = 1;

    public ?string $mediaPickerError = null;

    public bool $mediaPickerLoading = false;

    /** @var list<array<string, mixed>>|null */
    public ?array $mediaPickerArticleCatalog = null;

    /** @var 'original'|'local'|'article' */
    public string $mediaPickerTab = 'original';

    /** @var 'save'|'sync'|null Thu thập HTML sau khi flush FAQ (Lưu / Đồng bộ WP). */
    public ?string $pendingEditorCollectTarget = null;

    public ?string $pendingQuickTranslateLang = null;

    public ?int $pendingQuickTranslateTargetArticleId = null;

    public bool $articleHeavyActionBusy = false;

    /** @var 'save'|'sync'|null */
    public ?string $articleHeavyAction = null;

    /** Owning editor session id — set from React after acquire. Required for Livewire body writes. */
    public ?string $editorSessionId = null;

    /** Canonical document version known by React session client. */
    public ?int $expectedDocumentVersion = null;

    /** @var array<string, mixed>|null */
    public ?array $wpSyncContext = null;

    /** @var array<string, mixed>|null */
    public ?array $wpSyncPrepared = null;

    /** @var array<string, mixed>|null */
    public ?array $wpSyncDecoded = null;

    public bool $quickReviewsJobPending = false;

    /** @var list<int> Danh mục WordPress đã chọn (term/article id) cho tab Publish. */
    public array $articleCategoryIds = [];

    /** @var array<string, mixed>|null Cache post WP fetch trong một request mount. */
    private ?array $cachedWordPressPostPayload = null;

    /** @var array<string, mixed>|null */
    private ?array $cachedPublishCategoryOptions = null;

    protected string $bootstrapEditorHtml = '';

    /** True when article linked to WP but mount skipped remote refresh (Phase 1 perf). */
    public bool $wordpressMetadataStale = false;

    public string $manualWpPostId = '';

    /** @var array{wp_post_id?: int, duplicates?: list<array{id: int, title: string, edit_url: string, current: bool}>, remote_found?: bool|null, self_match?: bool, message?: string}|null */
    public ?array $manualWpPostLookup = null;

    public ?int $reviewsCountForEditor = null;

    public bool $editorPreparing = false;

    public string $editorPreparingMessage = '';

    public bool $pipelineRerunBusy = false;

    public bool $pipelineRerunWatching = false;

    public ?string $pipelineRerunStatus = null;

    public ?string $pipelineRerunUrl = null;

    public ?string $pipelineRerunMessage = null;

    /** @var array<string, mixed>|null Pending manual AI-history apply banner. */
    public ?array $aiHistoryPendingBanner = null;

    public function hasAiHistoryPendingBanner(): bool
    {
        return is_array($this->aiHistoryPendingBanner)
            && trim((string) ($this->aiHistoryPendingBanner['target'] ?? '')) !== '';
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Filament resolve record qua getEloquentQuery() có eager-load articleMetas
        // bị whitelist 5 key (tối ưu cho trang list). Relation đã "loaded" khiến mọi
        // loadMissing('articleMetas') sau đó bị skip → meta description/gallery trống.
        // Ép load lại ĐẦY ĐỦ metas trước khi hydrate.
        $this->record->load('articleMetas');

        if (! ArticleResource::canContentManagerAccessArticle($this->record)) {
            $this->redirect(
                ArticleResource::getUrl('access-denied', ['record' => $this->record->getKey()]),
                navigate: true,
            );

            return;
        }

        $articleSiteId = (int) ($this->record->site_id ?? 0);
        $globalSiteId = SeoAccessControl::globalSiteId();

        // Global domain = UI context only. Không 404 / không ép đổi domain khi mở record khác domain.
        $this->record->loadMissing('site');
        $this->recordDomainDiffersFromGlobal = $globalSiteId !== null
            && $articleSiteId > 0
            && $globalSiteId !== $articleSiteId;

        $readiness = app(ArticleEditorReadinessService::class)->evaluate($this->record);
        if (! $readiness->isReady) {
            $this->editorPreparing = true;
            $this->editorPreparingMessage = app(ArticleEditorReadinessService::class)->userMessage($readiness);
            $this->articleTitle = (string) ($this->record->title ?? '');

            return;
        }

        // Phase 1 perf: no remote WordPress HTTP on editor open — explicit Sync-from-WP flows use private sync* methods.
        $perf = app(ArticleEditorPerfDebug::class);
        $perf->start('edit_article_mount');
        $this->hydrateArticleState();
        $this->manualWpPostId = (int) ($this->record->wordpressLink?->wp_post_id ?? 0) > 0
            ? (string) ((int) $this->record->wordpressLink?->wp_post_id)
            : '';
        $this->manualWpPostLookup = null;

        if ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) > 0) {
            $this->wordpressMetadataStale = true;
        }

        $this->articleHeavyActionBusy = false;
        $this->articleHeavyAction = null;

        $activeOp = app(ArticleWpSyncQueueService::class)->activeOperation($this->record);
        if (
            is_array($activeOp)
            && in_array((string) ($activeOp['raw_status'] ?? ''), [
                ArticleWpSyncQueueService::STATUS_PENDING,
                ArticleWpSyncQueueService::STATUS_PROCESSING,
            ], true)
        ) {
            // Không giữ editor chờ worker — chuyển sang Sync Queue ngay.
            $queueUrl = ArticleResource::getUrl('index').'?tab=queue';
            $this->js(
                'window.__SEO_EDITOR_EXITING__=true;'
                .'window.__seoArticleOperationTracker?.stop?.();'
                .'window.location.replace('.json_encode($queueUrl).');'
            );
        }

        $restoredMessage = session()->pull('seo_revision_restored');
        if (is_string($restoredMessage) && $restoredMessage !== '') {
            Notification::make()
                ->title('Khôi phục phiên bản thành công')
                ->body($restoredMessage)
                ->success()
                ->send();
        }

        $perf->stop('edit_article_mount');
        $this->refreshPipelineRerunStatus();
        $perf->logSummary('edit_article_mount', ['article_id' => (int) $this->record->getKey()]);
    }

    /**
     * Livewire lifecycle hook — runs after the Blade view (and therefore every
     * getEditorXPayload() bootstrap getter it called) has rendered. Cheap no-op unless
     * ARTICLE_EDITOR_PERF_DEBUG=true (Phase 2 sizer).
     */
    public function dehydrate(): void
    {
        $perf = app(ArticleEditorPerfDebug::class);
        $perf->logBootstrapSizes('edit_article_render');
        $perf->logLivewireSnapshotEstimate('edit_article_dehydrate', [
            'articleTitle' => $this->articleTitle,
            'articleSlug' => $this->articleSlug,
            'seoTitle' => $this->seoTitle,
            'seoMetaDescription' => $this->seoMetaDescription,
            'seoTitleHydrated' => $this->seoTitleHydrated,
            'seoMetaDescriptionHydrated' => $this->seoMetaDescriptionHydrated,
            'focusKeyword' => $this->focusKeyword,
            'articleStatus' => $this->articleStatus,
            'visibility' => $this->visibility,
            'articlePostType' => $this->articlePostType,
            'featuredImageUrl' => $this->featuredImageUrl,
            'productGallery' => $this->productGallery,
            'mediaPickerImages' => $this->mediaPickerImages,
            'mediaPickerArticleCatalog' => $this->mediaPickerArticleCatalog,
            'wpSyncContext' => $this->wpSyncContext,
            'wpSyncPrepared' => $this->wpSyncPrepared,
            'wpSyncDecoded' => $this->wpSyncDecoded,
            'articleCategoryIds' => $this->articleCategoryIds,
            'editorPreparingMessage' => $this->editorPreparingMessage,
        ]);
    }

    public function pollEditorReadiness(): void
    {
        if (! $this->editorPreparing) {
            return;
        }

        $this->record->refresh();
        $readiness = app(ArticleEditorReadinessService::class)->evaluate($this->record);
        $this->editorPreparingMessage = app(ArticleEditorReadinessService::class)->userMessage($readiness);

        if (! $readiness->isReady) {
            return;
        }

        $this->editorPreparing = false;
        $this->editorPreparingMessage = '';
        // Same as mount: hydrate from local DB only, no remote WP HTTP.
        $this->hydrateArticleState();
        $this->manualWpPostId = (int) ($this->record->wordpressLink?->wp_post_id ?? 0) > 0
            ? (string) ((int) $this->record->wordpressLink?->wp_post_id)
            : '';
        $this->manualWpPostLookup = null;

        if ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) > 0) {
            $this->wordpressMetadataStale = true;
        }

        $this->articleHeavyActionBusy = false;
        $this->articleHeavyAction = null;
    }

    public function forceOpenEditorWhilePreparing(): void
    {
        if (! $this->editorPreparing) {
            return;
        }

        $this->record->refresh();
        app(ArticleEditorReadinessService::class)->abandonPreparingGate(
            $this->record,
            'Người dùng bỏ qua màn chuẩn bị editor (job AI/ảnh treo).',
        );

        $this->editorPreparing = false;
        $this->editorPreparingMessage = '';
        $this->hydrateArticleState();
        $this->manualWpPostId = (int) ($this->record->wordpressLink?->wp_post_id ?? 0) > 0
            ? (string) ((int) $this->record->wordpressLink?->wp_post_id)
            : '';
        $this->manualWpPostLookup = null;

        if ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) > 0) {
            $this->wordpressMetadataStale = true;
        }

        $this->articleHeavyActionBusy = false;
        $this->articleHeavyAction = null;
    }

    public function updatedArticleSlug($value): void
    {
        $normalized = Str::slug((string) $value);
        if ($this->articleSlug !== $normalized) {
            $this->articleSlug = $normalized;
        }

        $this->dispatchEditorSlugUpdated();
    }

    private function dispatchEditorSlugUpdated(): void
    {
        $slug = trim($this->articleSlug);
        $detail = [
            'slug' => $slug,
            'article_slug' => $slug,
            'permalink' => trim($this->getDisplayPermalink()),
            'wordpress_permalink' => trim($this->getObservedWordPressPermalink()),
            'permalink_base' => rtrim($this->getPermalinkBase(), '/'),
            'permalink_suffix' => $this->getPermalinkSuffix(),
        ];

        $this->js(sprintf(
            'window.dispatchEvent(new CustomEvent("seo-editor-slug-updated", { detail: %s }))',
            json_encode($detail, JSON_THROW_ON_ERROR),
        ));
    }

    public function confirmArticleSlug(?string $slug = null): void
    {
        if ($slug !== null) {
            $this->articleSlug = Str::slug($slug);
        }

        $this->persistArticleSlugFromEditor();
    }

    /**
     * @return array{google_serp_preview: array<string, mixed>, score: int|null, focus_keyword: string, article_slug: string, permalink: string, permalink_base: string, permalink_suffix: string, meta_description: string, seo_analysis_pending: bool}
     */
    public function updateSeoMetaFromEditor(string $focusKeyword, string $description, string $slug = ''): array
    {
        $this->focusKeyword = trim($focusKeyword);
        $this->seoMetaDescription = trim($description);

        if (trim($slug) !== '') {
            $this->articleSlug = Str::slug($slug);
        }

        $result = app(\Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher::class)->dispatch(
            'article.seo_meta.update',
            [
                'article_id' => (int) $this->record->id,
                'focus_keyword' => $this->focusKeyword,
                'meta_description' => $this->seoMetaDescription,
                'slug' => $this->articleSlug,
                'dispatch_scoring' => false,
            ],
            \Omnichannel\Addons\Agent\Automation\Data\ActionContext::fromArray([
                'origin' => 'filament.edit_article',
                'actor_id' => auth()->id() !== null ? (int) auth()->id() : null,
                'site_id' => (int) ($this->record->site_id ?? 0) ?: null,
            ]),
        );

        if (! $result->success) {
            throw new \RuntimeException((string) ($result->error['message'] ?? 'Không lưu được SEO meta.'));
        }

        $fresh = $this->record->fresh(['articleMetas', 'site']) ?? $this->record;
        $payload = app(ArticleEditorSeoMetaService::class)->buildResponse(
            $fresh,
            (string) ($result->output['focus_keyword'] ?? $this->focusKeyword),
            (string) ($result->output['meta_description'] ?? $this->seoMetaDescription),
            (string) ($result->output['slug'] ?? $this->articleSlug),
        );

        $preview = $payload['google_serp_preview'] ?? $this->getGoogleSerpPreview();
        $this->dispatch('google-serp-preview-updated', preview: $preview);

        return $payload;
    }

    private function persistArticleSlugFromEditor(bool $silent = false): void
    {
        $slug = Str::slug($this->articleSlug);
        if ($slug === '') {
            if (! $silent) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.media_library.invalid_slug'))
                    ->warning()
                    ->send();
            }

            return;
        }

        $previousSlug = trim((string) ($this->record->slug ?? ''));
        $this->articleSlug = $slug;

        if ($slug === $previousSlug) {
            return;
        }

        $this->record->update(['slug' => $slug]);
        $this->record->refresh();
        $this->dispatchGoogleSerpPreviewUpdated();

        if ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) <= 0) {
            if (! $silent) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.article_edit.slug_saved_local'))
                    ->body(__('seo-content-ai::filament.article_edit.slug_saved_local_no_wp'))
                    ->success()
                    ->send();
            }

            return;
        }

        $result = app(\Omnichannel\Addons\WordPress\Services\WordPressManualSyncService::class)
            ->syncSlug(
                $this->record->fresh(),
                auth()->user(),
                'article_editor.sync_slug',
                $slug,
            );
        if ($result['success']) {
            if (! $silent) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.article_edit.slug_synced'))
                    ->body((string) ($result['message'] ?? ''))
                    ->success()
                    ->send();
            }

            return;
        }

        if (! $silent) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.slug_sync_failed'))
                ->body((string) ($result['message'] ?? ''))
                ->warning()
                ->send();
        }
    }

    private function clearInvalidRankMathSeoTitleMeta(): void
    {
        // seo_title meta retired — articles.title is SoT.
        $this->record->articleMetas()->where('meta_key', 'seo_title')->delete();
        $this->seoTitle = trim($this->articleTitle);
        $this->seoTitleHydrated = $this->seoTitle;
    }

    public function updatedFocusKeyword(): void
    {
        $this->persistFocusKeyword();
        $this->record->refresh();
        $this->rescoreAndDispatchSeoAnalysis();

        $keyword = trim($this->focusKeyword);
        $this->js(sprintf(
            'window.dispatchEvent(new CustomEvent("seo-focus-keyword-updated", { detail: { focus_keyword: %s } }))',
            json_encode($keyword !== '' ? $keyword : null, JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * Khi mở trang sửa: lấy tiêu đề mới nhất từ WP nếu bài chưa chỉnh local (webhook có thể đã cập nhật DB).
     */
    private function syncTitleFromWordPressWhenAllowed(): void
    {
        if ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) <= 0) {
            return;
        }

        if (app(ArticleWordPressSyncFlagService::class)->shouldBlockWordPressImport($this->record)) {
            return;
        }

        $post = $this->fetchWordPressPostPayload(importFaqs: false);
        if ($post === []) {
            return;
        }

        $this->record->refresh();
    }

    /**
     * Luôn fetch danh mục từ WP khi mở trang (kể cả bài đang chặn import nội dung).
     */
    private function syncWordPressCategoriesOnLoad(): void
    {
        if ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) <= 0) {
            return;
        }

        $post = $this->fetchWordPressPostPayload(importFaqs: false);
        if ($post === []) {
            return;
        }

        $categoryIds = app(WordPressArticleContentService::class)->extractCategoryIdsFromPost($post);
        if ($categoryIds === []) {
            return;
        }

        $validIds = $this->filterPublishCategoryIds($categoryIds);
        if ($validIds === []) {
            return;
        }

        $this->applyArticleCategorySelection($validIds);
        $this->dispatchWordPressCategoriesToClient($validIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWordPressPostPayload(bool $importFaqs = false): array
    {
        if (is_array($this->cachedWordPressPostPayload)) {
            return $this->cachedWordPressPostPayload;
        }

        if ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) <= 0) {
            return [];
        }

        $post = app(WordPressArticleContentService::class)->fetchFromWordPress($this->record, importFaqs: $importFaqs);
        if ($post === []) {
            return [];
        }

        $this->cachedWordPressPostPayload = $post;

        return $post;
    }

    /**
     * @param  list<int>  $categoryIds
     * @return list<int>
     */
    private function filterPublishCategoryIds(array $categoryIds): array
    {
        if ($this->isTaxonomyArticle()) {
            return [];
        }

        $postType = SeoProjectTask::normalizePostType(
            trim($this->articlePostType) !== ''
                ? $this->articlePostType
                : ArticlePostTypeResolver::resolve($this->record),
        );
        $taxonomy = $this->resolvePublishCategoryTaxonomy($postType);

        $bundle = $this->getPublishCategoryOptions();
        $options = $bundle[$taxonomy] ?? [];
        $catalogOk = (bool) ($bundle['status'][$taxonomy]['ok'] ?? false);
        $optionIds = collect($options)
            ->map(static fn (array $option): int => (int) ($option['id'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        return PublishingTaxonomySelectionFilter::filter($categoryIds, $optionIds, $catalogOk);
    }

    /**
     * @param  list<int>  $categoryIds
     */
    private function applyArticleCategorySelection(array $categoryIds): void
    {
        $validIds = $this->filterPublishCategoryIds($categoryIds);
        $this->articleCategoryIds = $validIds;

        $this->record->articleMetas()->updateOrCreate(
            ['meta_key' => 'category_ids'],
            ['meta_value' => json_encode($validIds, JSON_THROW_ON_ERROR)],
        );
    }

    private function importFaqsFromWordPressOnLoad(): void
    {
        $this->record->loadCount('faqs');
        $hasLocalFaqs = $this->record->faqs_count > 0;

        if ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) > 0 && ! $hasLocalFaqs) {
            if (! $this->articleHasStoredWordPressFaqs($this->record)) {
                $post = $this->fetchWordPressPostPayload(importFaqs: false);
                $this->record->refresh();
                $this->bootstrapEditorHtml = app(WordPressArticleContentService::class)->resolveEditorHtml($this->record);
            }
        }

        if ($hasLocalFaqs) {
            return;
        }

        $result = app(ArticleFaqWordPressImportService::class)
            ->importWhenPanelEmpty($this->record, $this->bootstrapEditorHtml);

        if ($result['imported'] && ($result['faq_count'] ?? 0) > 0) {
            $this->record->load('faqs');
            $editorHtml = (string) ($result['editor_html'] ?? $this->bootstrapEditorHtml);
            if ($editorHtml !== '') {
                $this->bootstrapEditorHtml = $editorHtml;
            }

            $this->dispatch(
                'article-faqs-extracted',
                faqs: $result['faqs'],
                editorHtml: $editorHtml,
            );

            return;
        }

        $this->dispatchFaqExtractDebugIfPresent($result['extract_debug'] ?? null);
    }

    private function articleHasStoredWordPressFaqs(\Omnichannel\Addons\Content\Models\SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', 'wp_faqs')?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return false;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && $decoded !== [];
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    /**
     * Page-scoped body class — ẩn Filament topbar chỉ trên Article Editor.
     *
     * @return array<string, string>
     */
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'article-editor-page',
        ];
    }

    protected function getHeaderActions(): array
    {
        // Prompts / Assign / Open project render in More menu (page-actions Blade).
        // Header bị CSS ẩn — không phụ thuộc JS move từ .fi-header.
        return [];
    }

    public function delete(): void
    {
        $record = $this->record;
        abort_unless(
            $record instanceof SeoArticle && ArticleResource::canDelete($record),
            403,
        );

        $title = trim((string) ($record->title ?? ''));
        $record->delete();

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.delete_success'))
            ->body($title !== ''
                ? __('seo-content-ai::filament.article_list.delete_success_body', ['title' => $title])
                : __('seo-content-ai::filament.article_list.delete_success_body_untitled'))
            ->success()
            ->send();

        $this->redirect(ArticleResource::getUrl('index'));
    }

    public function syncArticleFromWordPress(): bool
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        $result = app(SyncDomainContentService::class)->syncSingleArticleFromWordPress($this->record);

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.fetch_from_wordpress_failed'))
                ->body((string) ($result['message'] ?? __('seo-content-ai::filament.article_list.fetch_from_wordpress_failed_body')))
                ->danger()
                ->send();

            return false;
        }

        $categoryIds = is_array($result['category_ids'] ?? null) ? $result['category_ids'] : [];
        $filteredCategoryIds = $this->filterPublishCategoryIds($categoryIds);
        if ($filteredCategoryIds !== []) {
            $this->applyArticleCategorySelection($filteredCategoryIds);
            $this->persistWordPressCategoriesToLocalStorage($filteredCategoryIds);
        }

        $this->record->refresh();
        app(ArticleKeywordLinkReconcileService::class)->reconcileForArticle($this->record->fresh());

        $inlineImages = (int) ($result['inline_images'] ?? 0);
        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.fetch_from_wordpress_success'))
            ->body(__('seo-content-ai::filament.article_list.fetch_from_wordpress_success_body', [
                'count' => $inlineImages,
            ]))
            ->success()
            ->send();

        $this->articleHeavyAction = 'restore';
        $this->finishHeavyArticleActionWithReload(clearLocalState: true);

        return true;
    }

    public function hasWpDataOutOfSync(): bool
    {
        return app(ArticleWordPressSyncFlagService::class)->hasDataOutOfSync($this->record);
    }

    protected function hydrateArticleState(): void
    {
        $service = app(WordPressArticleContentService::class);
        $this->restoreArticleBodyFromWordPressCacheIfMissing();
        app(ArticleScheduleReconcileService::class)->reconcileForEditor($this->record);
        $this->record->refresh();

        $flags = app(ArticleWordPressSyncFlagService::class);
        $metaMap = ArticleMetaMap::for($this->record);

        $this->articleTitle = $flags->decodeWordPressText((string) ($this->record->title ?? ''));
        $this->articleSlug = $service->resolveSlug($this->record);
        $this->articlePostType = SeoProjectTask::normalizePostType(ArticlePostTypeResolver::resolve($this->record));
        $this->articleStatus = (string) ($this->record->status ?? 'draft');
        $this->visibility = $this->articleStatus === 'private' ? 'private' : 'public';

        // Phase 2: featured URL from local meta only — never WordPress HTTP on shell open.
        $featuredFromMeta = trim((string) $metaMap->get('wp_featured_image_url', ''));
        $this->featuredImageUrl = $featuredFromMeta !== '' ? $featuredFromMeta : null;

        // Phase 2: album stays out of Livewire snapshot until Images/gallery actions load it.
        $this->productGallery = [];

        // Phase 2: editor HTML from local body/meta only — no remote WP HTML fallback on mount.
        $localBody = trim((string) ($this->record->body ?? ''));
        if ($localBody === '') {
            $localBody = trim((string) $metaMap->get('wp_post_content', ''));
        }
        $this->bootstrapEditorHtml = app(ArticleCtaPlaceholderService::class)->highlightBlankPlaceholdersInHtml(
            $localBody,
            (int) ($this->record->site_id ?? 0) > 0 ? (int) $this->record->site_id : null,
        );
        $this->hydrateSeoMetaState();
        $this->loadArticleCategoryIdsFromMeta();
        $this->syncPublishDatePartsFromRecord();
        $this->refreshAiHistoryPendingBanner();
    }

    private function refreshAiHistoryPendingBanner(): void
    {
        $pending = app(ArticleAiHistoryPendingDraftStore::class)->get($this->record);
        if ($pending === null) {
            $this->aiHistoryPendingBanner = null;

            return;
        }

        $this->aiHistoryPendingBanner = [
            'target' => (string) ($pending['target'] ?? ''),
            'run_id' => $pending['run_id'] ?? null,
            'attempt' => $pending['attempt'] ?? null,
            'artifact_ref' => (string) ($pending['artifact_ref'] ?? ''),
            'payload' => (string) ($pending['payload'] ?? ''),
            'apply_mode' => (string) ($pending['apply_mode'] ?? 'manual_debug_apply'),
        ];
    }

    public function undoAiHistoryPendingApply(): void
    {
        $result = app(ArticleAiHistoryApplicationService::class)->undoPending(
            $this->record,
            (int) (auth()->id() ?? 0),
        );
        $this->refreshAiHistoryPendingBanner();

        if ($result->success) {
            Notification::make()
                ->title('Đã hoàn tác')
                ->body($result->message)
                ->success()
                ->send();
            $this->js(
                'window.__SEO_EDITOR_EXITING__=true;'
                .'window.__seoMarkIntentionalEditorClose?.();'
                .'window.setTimeout(() => window.location.reload(), 120)'
            );

            return;
        }

        Notification::make()
            ->title('Không hoàn tác được')
            ->body($result->message)
            ->danger()
            ->send();
    }

    private function restoreArticleBodyFromWordPressCacheIfMissing(): void
    {
        if (trim((string) ($this->record->body ?? '')) !== '') {
            return;
        }

        $cached = trim((string) (ArticleMetaMap::for($this->record)->get('wp_post_content', '')));

        if ($cached === '') {
            return;
        }

        $active = app(ArticleEditorSessionService::class)->findActiveSession($this->record);
        if ($active !== null) {
            return;
        }

        $this->record->update(['body' => $cached]);
        $this->record->refresh();
    }

    private function articleHadSubstantialContent(): bool
    {
        if (trim((string) ($this->record->body ?? '')) !== '') {
            return true;
        }

        $cached = trim((string) (ArticleMetaMap::for($this->record)->get('wp_post_content', '')));

        if (strlen($cached) >= 200) {
            return true;
        }

        return $this->record->headings()->exists();
    }

    private function guardArticleBodyBeforeSave(string $html): string
    {
        $html = trim($html);
        if (strlen($html) >= 200) {
            return $html;
        }

        $existingBody = trim((string) ($this->record->body ?? ''));
        if (strlen($existingBody) >= 200) {
            return $existingBody;
        }

        $wpCached = trim((string) (ArticleMetaMap::for($this->record)->get('wp_post_content', '')));

        if (strlen($wpCached) >= 200) {
            return $wpCached;
        }

        return $html;
    }

    /** Không đặt tên hydrate{Property} — Livewire sẽ coi là lifecycle hook và gọi từ ngoài. */
    private function loadArticleCategoryIdsFromMeta(): void
    {
        $metaMap = ArticleMetaMap::for($this->record);

        if ($this->isTaxonomyEntityForPublish()) {
            $parentId = max(0, (int) $metaMap->get('wp_parent_id', '0'));
            $parentId = $this->filterPublishParentId($parentId);
            $this->articleCategoryIds = $parentId > 0 ? [$parentId] : [];

            return;
        }

        $raw = (string) $metaMap->get('category_ids', '');
        if ($raw === '') {
            $raw = (string) $metaMap->get('wp_category_ids', '');
        }

        $decoded = json_decode($raw, true);

        $this->articleCategoryIds = is_array($decoded)
            ? $this->filterPublishCategoryIds(
                collect($decoded)
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all(),
            )
            : [];
    }

    /**
     * WordPress taxonomy catalog (post → category, product → product_cat) cho tab Publish.
     *
     * @return array{
     *     category: list<array{id: int, label: string}>,
     *     product_category: list<array{id: int, label: string}>,
     *     status?: array<string, array{ok: bool, code: string, message: string, taxonomy: string}>
     * }
     */
    public function getPublishCategoryOptions(): array
    {
        if (is_array($this->cachedPublishCategoryOptions)) {
            return $this->cachedPublishCategoryOptions;
        }

        $siteId = (int) ($this->record->site_id ?? 0);
        $this->cachedPublishCategoryOptions = app(PublishCategoryOptionsAssembler::class)->forSite($siteId);

        return $this->cachedPublishCategoryOptions;
    }

    public function resolvePublishCategoryTaxonomy(?string $postType = null): string
    {
        $this->record->loadMissing('articleMetas');

        if ($this->isTaxonomyEntityForPublish()) {
            return $this->resolveTaxonomyParentOptionKey();
        }

        $resolved = \Omnichannel\Addons\Content\Support\PublishingTaxonomyResolver::resolve(
            $postType ?? ArticlePostTypeResolver::resolve($this->record),
            ArticlePostTypeResolver::contentType($this->record)->value,
        );

        return $resolved['taxonomy'] ?? 'category';
    }

    /**
     * Lưu danh mục đã chọn từ tab Publish (client Alpine) vào article meta.
     *
     * @param  list<int|string>  $categoryIds
     */
    public function applyArticleCategoriesFromClient(array $categoryIds): void
    {
        if ($this->isTaxonomyEntityForPublish()) {
            $parentId = collect($categoryIds)
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->first();

            $parentId = $this->filterPublishParentId($parentId ?? 0);
            $this->persistTaxonomyParentId($parentId);
            $this->articleCategoryIds = $parentId > 0 ? [$parentId] : [];
            $this->skipRender();

            return;
        }

        $this->applyArticleCategorySelection(
            collect($categoryIds)
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all(),
        );

        $this->skipRender();
    }

    private function persistTaxonomyParentId(int $parentId): void
    {
        // Root terms use parent 0 — persist as "0", never delete (Site MCP fail-closed).
        $this->record->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_parent_id'],
            ['meta_value' => (string) max(0, $parentId)],
        );
    }

    private function resolveTaxonomyParentOptionKey(): string
    {
        $type = SeoProjectTask::normalizePostType(ArticlePostTypeResolver::resolve($this->record));

        return $type === SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY ? 'product_category' : 'category';
    }

    private function filterPublishParentId(int $parentId): int
    {
        if ($parentId <= 0) {
            return 0;
        }

        $selfWpId = (int) ($this->record->wordpressLink?->wp_post_id ?? 0);
        if ($selfWpId > 0 && $parentId === $selfWpId) {
            return 0;
        }

        $bundle = $this->getPublishCategoryOptions();
        $options = $bundle[$this->resolveTaxonomyParentOptionKey()] ?? [];
        $catalogOk = (bool) ($bundle['status'][$this->resolveTaxonomyParentOptionKey()]['ok'] ?? false);
        $optionIds = collect($options)
            ->map(static fn (array $option): int => (int) ($option['id'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $filtered = PublishingTaxonomySelectionFilter::filter([$parentId], $optionIds, $catalogOk);

        return $filtered[0] ?? 0;
    }

    /**
     * @param  list<int>  $categoryIds
     */
    private function dispatchWordPressCategoriesToClient(array $categoryIds): void
    {
        $categoryIds = $this->filterPublishCategoryIds($categoryIds);
        if ($categoryIds === []) {
            return;
        }

        $this->persistWordPressCategoriesToLocalStorage($categoryIds);
    }

    /**
     * @param  list<int|string>  $categoryIds
     */
    private function persistWordPressCategoriesToLocalStorage(array $categoryIds): void
    {
        $categoryIds = collect($categoryIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($categoryIds === []) {
            return;
        }

        $articleId = (int) $this->record->getKey();
        $payload = json_encode([
            'articleId' => $articleId,
            'categoryIds' => $categoryIds,
            'fetchedAt' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->js(sprintf(
            'window.__seoWpCategoryStorage?.applyFetched?.(%s); window.dispatchEvent(new CustomEvent("seo-wp-categories-fetched", { detail: %s }));',
            $payload,
            $payload,
        ));
    }

    public function isProduct(): bool
    {
        $type = strtolower(trim(SeoProjectTask::normalizePostType($this->articlePostType)));

        return in_array($type, ['product', 'e-commerce'], true);
    }

    public function isTaxonomyArticle(): bool
    {
        return ArticlePostTypeResolver::isTerm($this->record);
    }

    private function isTaxonomyEntityForPublish(): bool
    {
        return ArticlePostTypeResolver::isTerm($this->record);
    }

    public function supportsProductGallery(): bool
    {
        return $this->isProduct() && ! $this->isTaxonomyArticle();
    }

    /**
     * Backend SoT for Mode 2 UI — feature flag/allowlist only (not model reference capability).
     */
    private function resolveParentChildAllowedForEditor(): bool
    {
        if (! $this->supportsProductGallery()) {
            return false;
        }

        $articleId = (int) ($this->record->id ?? 0);

        return $articleId > 0
            && \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature::allowsArticle($articleId);
    }

    private function resolveParentChildReasonForEditor(): string
    {
        if (! $this->supportsProductGallery()) {
            return 'not_product_gallery';
        }

        $articleId = (int) ($this->record->id ?? 0);
        if ($articleId <= 0) {
            return 'invalid_article';
        }

        if (\Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature::allowsArticle($articleId)) {
            return '';
        }

        if (! \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryParentChildFeature::enabled()) {
            return 'feature_disabled';
        }

        return 'article_not_allowlisted';
    }

    public function armEditorBlockMediaPicker(string $blockId): void
    {
        $blockId = trim($blockId);
        if ($blockId === '') {
            return;
        }

        $this->mediaPickerTargetBlockId = $blockId;
        $this->mediaPickerMode = 'editor-block';
    }

    #[On('append-editor-image-to-product-gallery')]
    public function appendEditorImageToProductGallery(
        string $url = '',
        int $wpAttachmentId = 0,
        int $seoMediaId = 0,
        string $slug = '',
        string $alt = '',
    ): void {
        if (! $this->supportsProductGallery()) {
            Notification::make()
                ->title('Không áp dụng album sản phẩm')
                ->body('Chỉ bài sản phẩm WooCommerce mới có album hình ảnh.')
                ->warning()
                ->send();

            return;
        }

        $url = trim($url);
        if ($url === '') {
            Notification::make()
                ->title('Thiếu URL ảnh')
                ->body('Không thể thêm ảnh vào album vì thiếu đường dẫn.')
                ->warning()
                ->send();

            return;
        }

        $wpAttachmentId = max(0, $wpAttachmentId);
        $seoMediaId = max(0, $seoMediaId);
        $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

        if ($localRefId <= 0) {
            $localRefId = app(ArticleMediaLocalService::class)
                ->resolveLocalRefIdFromImageUrl((int) ($this->record->site_id ?? 0), $url);
        }

        if ($localRefId <= 0) {
            Notification::make()
                ->title('Không thể thêm vào album')
                ->body('Ảnh chưa có ID thư viện (WP hoặc Laravel). Hãy chọn ảnh từ thư viện hoặc đồng bộ WordPress trước.')
                ->warning()
                ->send();

            return;
        }

        $beforeCount = count($this->productGallery);
        $this->productGallery = app(ArticleMediaLocalService::class)
            ->appendProductAlbumLocal($this->record, $localRefId, $url);
        $afterCount = count($this->productGallery);

        if ($afterCount <= $beforeCount) {
            Notification::make()
                ->title('Ảnh đã có trong album')
                ->info()
                ->send();

            return;
        }

        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        $this->record->refresh();

        $this->syncProductGalleryToEditor();

        $this->dispatch(
            'article-media-selected',
            mode: 'gallery',
            url: $url,
            wpAttachmentId: $wpAttachmentId > 0 ? $wpAttachmentId : null,
            seoMediaId: $seoMediaId > 0 ? $seoMediaId : null,
            slug: trim($slug),
            alt: trim($alt),
        );
    }

    /**
     * @param  array{title?: string, body?: string, status?: string}  $payload
     */
    #[On('seo-article-editor-notify')]
    public function handleEditorNotify(array $payload = []): void
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        if ($title === '' && $body === '') {
            return;
        }

        $notification = Notification::make();
        if ($title !== '') {
            $notification->title($title);
        }
        if ($body !== '') {
            $notification->body($body);
        }

        match ((string) ($payload['status'] ?? 'success')) {
            'danger', 'error' => $notification->danger(),
            'warning' => $notification->warning(),
            'info' => $notification->info(),
            default => $notification->success(),
        };

        $notification->send();
    }

    public function prepareMediaPicker(string $mode = 'featured', ?string $blockId = null): void
    {
        if ($mode !== 'editor-block' && (int) ($this->record->wordpressLink?->wp_post_id ?? 0) <= 0) {
            Notification::make()
                ->title('WordPress not linked')
                ->body('Sync article from domain before selecting WordPress images.')
                ->warning()
                ->send();

            $this->mediaPickerOpen = false;
            $this->dispatch('close-article-media-modal');

            return;
        }

        if ($mode === 'editor-block') {
            $blockId = trim((string) ($blockId ?? ''));
            if ($blockId === '') {
                $this->mediaPickerOpen = false;

                Notification::make()
                    ->title('Unable to identify image block')
                    ->warning()
                    ->send();

                $this->dispatch('close-article-media-modal');

                return;
            }

            $this->mediaPickerTargetBlockId = $blockId;
            $this->mediaPickerMode = 'editor-block';
            $this->mediaPickerTab = 'article';
            $this->mediaPickerPage = 1;
            $this->mediaPickerError = null;
            $this->mediaPickerSearch = '';
            $this->mediaPickerOpen = true;
            $this->dispatch('open-article-media-modal');

            return;
        } else {
            $this->mediaPickerTargetBlockId = null;
            $this->mediaPickerMode = $mode === 'gallery' ? 'gallery' : 'featured';
        }

        $this->mediaPickerTab = $this->mediaPickerMode === 'editor-block' ? 'article' : 'original';
        $this->mediaPickerPage = 1;
        $this->mediaPickerError = null;
        $this->mediaPickerImages = [];
        $this->mediaPickerArticleCatalog = null;
        $this->mediaPickerSearch = '';
        $this->mediaPickerLoading = true;
        $this->mediaPickerOpen = true;
        $this->dispatch('open-article-media-modal');
        $this->loadMediaPickerImages();
    }

    public function setMediaPickerTab(string $tab): void
    {
        $tab = match ($tab) {
            'local', 'article' => $tab,
            default => 'original',
        };
        if ($this->mediaPickerTab === $tab) {
            return;
        }

        $this->mediaPickerTab = $tab;
        $this->mediaPickerPage = 1;
        $this->mediaPickerSearch = '';

        if ($tab === 'article') {
            return;
        }

        $this->mediaPickerArticleCatalog = null;
        $this->dispatch('article-media-picker-loading');
        $this->loadMediaPickerImages();
    }

    public function closeMediaPicker(): void
    {
        $this->mediaPickerOpen = false;
        $this->mediaPickerTargetBlockId = null;
    }

    public function searchMediaPicker(string $query): void
    {
        if ($this->mediaPickerTab === 'article') {
            return;
        }

        $this->mediaPickerSearch = trim($query);
        $this->mediaPickerPage = 1;
        $this->dispatch('article-media-picker-loading');
        $this->loadMediaPickerImages();
    }

    public function mediaPickerPreviousPage(): void
    {
        if ($this->mediaPickerPage <= 1) {
            return;
        }

        $this->goToMediaPickerPage($this->mediaPickerPage - 1);
    }

    public function mediaPickerNextPage(): void
    {
        if ($this->mediaPickerPage >= $this->mediaPickerTotalPages) {
            return;
        }

        $this->goToMediaPickerPage($this->mediaPickerPage + 1);
    }

    public function loadMediaPickerImages(): void
    {
        $this->mediaPickerLoading = true;
        $this->dispatch('article-media-picker-loading');
        $this->mediaPickerError = null;

        $this->record->loadMissing('site');
        $site = $this->record->site;
        if ($site === null) {
            $this->mediaPickerError = 'Domain not found.';
            $this->mediaPickerLoading = false;

            return;
        }

        $search = trim($this->mediaPickerSearch);
        $articleId = (int) $this->record->id;
        $library = app(SeoMediaLibraryService::class);
        $accessScope = app(MediaLibraryAccessScope::class);
        $restrictArticleIds = $accessScope->restrictedArticleIdsForSite((int) $site->id);
        $restrictWpAttachmentIds = $accessScope->pickerWordPressAttachmentRestrictions((int) $site->id, $search);

        if ($this->mediaPickerTab === 'article') {
            $postImagesResult = app(ArticlePostImagesService::class)->fetchForMediaPicker(
                $this->record,
                1,
                null,
                96,
            );
            $postImages = is_array($postImagesResult['images'] ?? null) ? $postImagesResult['images'] : [];
            $supplementalImages = $this->getEditorSupplementalImagesPayload();

            $seen = [];
            $merged = [];
            $append = static function (array $row) use (&$merged, &$seen): void {
                $src = trim((string) ($row['url'] ?? $row['src'] ?? ''));
                if ($src === '') {
                    return;
                }

                $wpId = (int) ($row['wp_attachment_id'] ?? 0);
                $seoId = (int) ($row['seo_media_id'] ?? 0);
                $identity = $wpId > 0
                    ? 'wp:'.$wpId
                    : ($seoId > 0 ? 'seo:'.$seoId : 'src:'.mb_strtolower($src));
                if (isset($seen[$identity])) {
                    return;
                }

                $seen[$identity] = true;
                $merged[] = [
                    'id' => (int) ($row['id'] ?? ($wpId > 0 ? $wpId : ($seoId > 0 ? $seoId : count($merged) + 1))),
                    'wp_attachment_id' => $wpId > 0 ? $wpId : null,
                    'seo_media_id' => $seoId > 0 ? $seoId : null,
                    'url' => $src,
                    'thumb_url' => trim((string) ($row['thumb_url'] ?? $src)),
                    'media_type' => (string) ($row['media_type'] ?? 'image'),
                    'alt' => trim((string) ($row['alt'] ?? '')),
                    'slug' => trim((string) ($row['slug'] ?? '')),
                ];
            };

            foreach ($postImages as $row) {
                $append($row);
            }
            foreach ($supplementalImages as $row) {
                $append($row);
            }

            $this->mediaPickerArticleCatalog = $merged;
            $this->applyArticleCatalogPickerPage();

            $result = [
                'images' => $this->mediaPickerImages,
                'total_pages' => $this->mediaPickerTotalPages,
                'page' => $this->mediaPickerPage,
                'error' => null,
            ];
        } else {
            if ($this->mediaPickerTab === 'local') {
                $library->assignRecentOrphanMediaToArticle($site, $articleId);
            }

            $result = $this->mediaPickerTab === 'local'
                ? $library->fetch(
                    $site,
                    null,
                    $this->mediaPickerPage,
                    $search !== '' ? $search : null,
                    28,
                    restrictToArticleIds: $restrictArticleIds,
                )
                : app(WordPressMediaLibraryService::class)->fetch(
                    $site,
                    null,
                    $this->mediaPickerPage,
                    28,
                    $search !== '' ? $search : null,
                    includeAttachmentIds: $restrictWpAttachmentIds,
                );

            $images = is_array($result['images'] ?? null) ? $result['images'] : [];
            $this->mediaPickerImages = $this->mediaPickerTab === 'local'
                ? app(MediaLibraryArticleResolver::class)->enrichImages((int) $site->id, $images)
                : $images;
        }
        $this->mediaPickerTotalPages = max(1, (int) ($result['total_pages'] ?? 1));
        $this->mediaPickerPage = max(1, (int) ($result['page'] ?? $this->mediaPickerPage));
        $this->mediaPickerError = filled($result['error'] ?? null) ? (string) $result['error'] : null;
        $this->mediaPickerLoading = false;
        $this->broadcastMediaPickerToClient();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeMediaPickerImagesForClient(array $images): array
    {
        $tab = (string) $this->mediaPickerTab;

        return array_values(array_map(function (array $image) use ($tab): array {
            $wpId = (int) ($image['wp_attachment_id'] ?? ($tab === 'original' ? ($image['id'] ?? 0) : 0));
            $seoId = (int) ($image['seo_media_id'] ?? ($tab === 'local' ? ($image['id'] ?? 0) : 0));
            $url = trim((string) ($image['url'] ?? ''));
            $thumbUrl = trim((string) ($image['thumb_url'] ?? $url));
            $pickerKey = $tab.'-'.($seoId > 0 ? 'seo-'.$seoId : 'wp-'.$wpId).'-'.md5($url);

            return [
                'picker_key' => $pickerKey,
                'id' => (int) ($image['id'] ?? ($wpId > 0 ? $wpId : ($seoId > 0 ? $seoId : 0))),
                'wp_attachment_id' => $wpId,
                'seo_media_id' => $seoId,
                'url' => $url,
                'thumb_url' => $thumbUrl !== '' ? $thumbUrl : $url,
                'slug' => trim((string) ($image['slug'] ?? '')),
                'alt' => trim((string) ($image['alt'] ?? '')),
                'media_type' => strtolower(trim((string) ($image['media_type'] ?? 'image'))) === 'video' ? 'video' : 'image',
            ];
        }, $images));
    }

    private function broadcastMediaPickerToClient(): void
    {
        $catalog = $this->mediaPickerTab === 'article' && $this->mediaPickerArticleCatalog !== null
            ? $this->normalizeMediaPickerImagesForClient($this->mediaPickerArticleCatalog)
            : null;

        $this->dispatch(
            'article-media-picker-loaded',
            images: $this->normalizeMediaPickerImagesForClient($this->mediaPickerImages),
            catalog: $catalog,
            page: $this->mediaPickerPage,
            totalPages: $this->mediaPickerTotalPages,
            error: $this->mediaPickerError,
            tab: $this->mediaPickerTab,
        );
    }

    private function applyArticleCatalogPickerPage(): void
    {
        $catalog = $this->mediaPickerArticleCatalog ?? [];
        $search = trim($this->mediaPickerSearch);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $catalog = array_values(array_filter($catalog, static function (array $row) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) ($row['slug'] ?? ''),
                    (string) ($row['alt'] ?? ''),
                    (string) ($row['url'] ?? ''),
                ])));

                return str_contains($haystack, $needle);
            }));
        }

        $perPage = 28;
        $total = count($catalog);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $this->mediaPickerPage), $totalPages);
        $offset = ($page - 1) * $perPage;

        $this->mediaPickerTotalPages = $totalPages;
        $this->mediaPickerPage = $page;
        $this->mediaPickerImages = array_slice($catalog, $offset, $perPage);
    }

    public function goToMediaPickerPage(int $page): void
    {
        $page = max(1, $page);
        if ($page > $this->mediaPickerTotalPages) {
            return;
        }

        $this->mediaPickerPage = $page;

        if ($this->mediaPickerTab === 'article' && $this->mediaPickerArticleCatalog !== null) {
            $this->applyArticleCatalogPickerPage();
            $this->broadcastMediaPickerToClient();

            return;
        }

        $this->dispatch('article-media-picker-loading');
        $this->loadMediaPickerImages();
    }

    public function reloadMediaPickerImages(): void
    {
        $this->loadMediaPickerImages();
    }

    public function selectMediaFromPicker(
        int $wpAttachmentId,
        string $url,
        string $alt = '',
        string $slug = '',
        int $seoMediaId = 0,
        string $mediaType = 'image',
        ?string $pickerMode = null,
        ?string $pickerTab = null,
        ?string $targetBlockId = null,
    ): void {
        $url = WordPressImageUrl::toFullSize(trim($url));
        if ($url === '') {
            return;
        }

        $resolvedMode = in_array($pickerMode, ['featured', 'gallery', 'editor-block'], true)
            ? $pickerMode
            : $this->mediaPickerMode;
        $resolvedTab = in_array($pickerTab, ['article', 'original', 'local'], true)
            ? $pickerTab
            : $this->mediaPickerTab;

        $slug = trim($slug);
        if ($slug === '' || WordPressImageUrl::isScaledVariant($url)) {
            $slug = WordPressImageUrl::slugFromUrl($url);
        }

        $seoMediaId = max(0, $seoMediaId);
        $wpAttachmentId = max(0, $wpAttachmentId);
        $mediaType = strtolower(trim($mediaType)) === 'video' ? 'video' : 'image';
        $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

        if ($resolvedMode === 'editor-block') {
            if ($resolvedTab === 'local' && $seoMediaId <= 0 && $wpAttachmentId <= 0) {
                return;
            }

            if ($resolvedTab === 'original' && $wpAttachmentId <= 0) {
                return;
            }

            $blockId = trim((string) ($targetBlockId ?? $this->mediaPickerTargetBlockId ?? ''));
            if ($blockId === '') {
                return;
            }

            $this->dispatch(
                'editor-block-image-selected',
                blockId: $blockId,
                attachmentId: $wpAttachmentId,
                seoMediaId: $seoMediaId,
                mediaType: $mediaType,
                url: $url,
                alt: trim($alt),
                slug: trim($slug),
                pickerTab: $resolvedTab,
            );

            $this->mediaPickerTargetBlockId = null;

            return;
        }

        if ($localRefId <= 0) {
            return;
        }

        if ($resolvedMode === 'gallery') {
            return;
        }

        if ($mediaType !== 'image') {
            Notification::make()
                ->title('Featured image only supports image files')
                ->warning()
                ->send();

            return;
        }

        $localMedia = app(ArticleMediaLocalService::class);

        $localMedia->applyFeaturedLocal($this->record, $localRefId, $url);
        $this->featuredImageUrl = trim($url);

        $this->record->refresh();
        $this->syncProductGalleryToEditor();
        $this->dispatch(
            'article-media-selected',
            mode: $resolvedMode,
            url: $url,
            wpAttachmentId: $wpAttachmentId > 0 ? $wpAttachmentId : null,
            seoMediaId: $seoMediaId > 0 ? $seoMediaId : null,
            slug: trim($slug),
            alt: trim($alt),
        );

        $this->dispatch('close-article-media-modal');
    }

    /**
     * @param  array{items?: list<array<string, mixed>>|list<array<string, mixed>>}  $payload
     */
    public function confirmGallerySelectionFromPicker(array $payload): void
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : $payload;
        $items = array_values(array_filter($items, static fn (mixed $row): bool => is_array($row)));
        if (! $this->supportsProductGallery()) {
            Notification::make()
                ->title('Album not applicable')
                ->body('Category only supports featured image.')
                ->warning()
                ->send();

            return;
        }

        if ($items === []) {
            Notification::make()
                ->title('Chưa chọn ảnh')
                ->body('Hãy chọn ít nhất một ảnh trước khi thêm vào album.')
                ->warning()
                ->send();

            return;
        }

        $localMedia = app(ArticleMediaLocalService::class);
        $added = 0;
        $skipped = 0;

        $album = $this->productGallery;
        if ($album === []) {
            $this->record->unsetRelation('articleMetas');
            $album = $localMedia->resolveProductAlbum($this->record);
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
            $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? 0));
            $mediaType = strtolower(trim((string) ($item['media_type'] ?? $item['mediaType'] ?? 'image')));
            if ($mediaType !== '' && $mediaType !== 'image') {
                $skipped++;

                continue;
            }
            $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

            if ($localRefId <= 0) {
                $localRefId = $localMedia->resolveLocalRefIdFromImageUrl((int) ($this->record->site_id ?? 0), $url);
            }

            if ($localRefId <= 0) {
                $skipped++;

                continue;
            }

            $duplicate = collect($album)->contains(
                static fn (array $row): bool => ((int) ($row['id'] ?? 0) > 0 && (int) ($row['id'] ?? 0) === $localRefId)
                    || (string) ($row['url'] ?? '') === $url
            );
            if ($duplicate) {
                $skipped++;

                continue;
            }

            $album[] = [
                'id' => $localRefId,
                'url' => $url,
            ];
            $added++;
        }

        if ($added > 0) {
            $this->productGallery = $localMedia->saveProductAlbumLocal($this->record, $album);
        }

        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        $this->record->refresh();
        $this->syncProductGalleryToEditor();

        $this->mediaPickerOpen = false;
        $this->dispatch('close-article-media-modal');

        if ($added <= 0) {
            Notification::make()
                ->title('Không thêm được ảnh mới')
                ->body($skipped > 0
                    ? 'Các ảnh đã chọn có thể đã có trong album hoặc thiếu ID thư viện.'
                    : 'Không có ảnh hợp lệ để thêm.')
                ->warning()
                ->send();

            return;
        }
    }

    public function removeProductGalleryImage(string $url): void
    {
        if (! $this->supportsProductGallery()) {
            return;
        }

        $this->productGallery = app(ArticleMediaLocalService::class)
            ->removeProductAlbumItemByUrl($this->record, $url);
        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        $this->record->refresh();
        $this->syncProductGalleryToEditor();
        $this->dispatch('article-media-removed', mode: 'gallery', url: trim($url));
    }

    /**
     * @param  list<string>  $orderedUrls
     */
    public function reorderProductGallery(array $orderedUrls = []): void
    {
        if (! $this->supportsProductGallery()) {
            return;
        }

        $this->productGallery = app(ArticleMediaLocalService::class)
            ->reorderProductAlbumLocal($this->record, $orderedUrls);
        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? null;
        $this->record->refresh();
        $this->syncProductGalleryToEditor();
    }

    private function syncProductGalleryToEditor(): void
    {
        if (! $this->supportsProductGallery()) {
            return;
        }

        $this->dispatch(
            'seo-product-gallery-updated',
            gallery: $this->productGallery,
            article_id: (int) $this->record->id,
        );
    }

    public function getPermalinkBase(): string
    {
        $this->record->loadMissing('site');
        if (! $this->record->site) {
            return '';
        }

        return app(WordPressArticleContentService::class)->getPermalinkBase($this->record->site);
    }

    public function getDisplaySlug(): string
    {
        $slug = Str::slug($this->articleSlug !== '' ? $this->articleSlug : $this->articleTitle);

        return $slug !== '' ? $slug : 'sample-post';
    }

    public function getPermalinkSuffix(): string
    {
        $permalink = trim($this->getDisplayPermalink());
        if ($permalink === '') {
            return '';
        }

        $slug = trim($this->articleSlug !== '' ? $this->articleSlug : (string) ($this->record->slug ?? ''));
        if ($slug === '') {
            return '';
        }

        $path = (string) parse_url($permalink, PHP_URL_PATH);
        $basename = trim((string) basename($path));
        if ($basename === '') {
            return '';
        }

        $prefix = $slug.'.';
        if (str_starts_with($basename, $prefix)) {
            return substr($basename, strlen($slug));
        }

        return '';
    }

    public function getDisplayPermalink(): string
    {
        $displaySlug = $this->getDisplaySlug();
        $preview = app(\Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder::class)
            ->preview($this->record, $displaySlug);
        if ($preview !== '') {
            return $preview;
        }

        $base = $this->getPermalinkBase();

        return $base !== ''
            ? rtrim($base, '/').'/'.$displaySlug
            : '';
    }

    /**
     * Observed WordPress permalink (meta wp_permalink). Never invent from editor slug.
     */
    public function getObservedWordPressPermalink(): string
    {
        $this->record->loadMissing('articleMetas');

        return trim((string) (
            $this->record->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''
        ));
    }

    public function permalinksAreEquivalent(string $left, string $right): bool
    {
        $a = $this->normalizePermalinkForCompare($left);
        $b = $this->normalizePermalinkForCompare($right);

        return $a !== '' && $a === $b;
    }

    public function normalizePermalinkForCompare(string $url): string
    {
        return rtrim(mb_strtolower(trim($url)), '/');
    }

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     url: string,
     *     description: string,
     *     display_url: string,
     *     meta: array<string, mixed>
     * }
     */
    public function getGoogleSerpPreview(): array
    {
        return app(ArticleGoogleSerpPreviewService::class)->buildForArticle(
            $this->record,
            trim($this->articleTitle),
            trim($this->seoMetaDescription),
            $this->getDisplayPermalink(),
        );
    }

    public function getArticlePermalink(): string
    {
        if ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) <= 0) {
            return '';
        }

        return app(WordPressArticleContentService::class)
            ->resolveStoredWordPressPermalink($this->record);
    }

    public function lookupManualWordPressPostId(): void
    {
        $wpPostId = $this->normalizeManualWpPostId();
        if ($wpPostId <= 0) {
            $this->manualWpPostLookup = [
                'duplicates' => [],
                'remote_found' => false,
                'message' => 'Nhập WP ID dạng số để kiểm tra.',
            ];

            return;
        }

        $duplicates = $this->manualWpPostIdDuplicateRows($wpPostId);

        $this->manualWpPostId = (string) $wpPostId;

        $blockingDuplicate = collect($duplicates)
            ->first(static fn (array $row): bool => ! (bool) ($row['current'] ?? false));
        $selfMatch = $duplicates !== [] && $blockingDuplicate === null;

        $this->manualWpPostLookup = [
            'wp_post_id' => $wpPostId,
            'duplicates' => $duplicates,
            'remote_found' => null,
            'self_match' => $selfMatch,
            'message' => match (true) {
                $selfMatch => 'WP ID này đã thuộc chính bài hiện tại. Không phải bài trùng và không cần nối lại.',
                $blockingDuplicate !== null => 'Tìm thấy bài local khác đang dùng WP ID này. Mở bài trùng để kiểm tra/xóa trước khi nối.',
                default => 'Chưa thấy bài local nào đang dùng WP ID này. Bấm Nối WP ID để kiểm tra WordPress và liên kết.',
            },
        ];
    }

    public function linkManualWordPressPostId(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title('Không có quyền cập nhật WP ID')
                ->danger()
                ->send();

            return;
        }

        $wpPostId = $this->normalizeManualWpPostId();
        if ($wpPostId <= 0) {
            Notification::make()
                ->title('WP ID không hợp lệ')
                ->body('Nhập ID bài viết WordPress dạng số, ví dụ 1234.')
                ->warning()
                ->send();

            return;
        }

        $duplicates = $this->manualWpPostIdDuplicateRows($wpPostId);
        $blockingDuplicate = collect($duplicates)
            ->first(static fn (array $row): bool => ! (bool) ($row['current'] ?? false));

        if (is_array($blockingDuplicate)) {
            $this->manualWpPostLookup = [
                'wp_post_id' => $wpPostId,
                'duplicates' => $duplicates,
                'remote_found' => null,
                'self_match' => false,
                'message' => 'WP ID này đang được nối với bài local khác. Mở bài trùng để xóa/sửa trước khi nối.',
            ];

            Notification::make()
                ->title('WP ID đã được nối với bài khác')
                ->body(sprintf('Article #%d: %s', (int) $blockingDuplicate['id'], Str::limit((string) $blockingDuplicate['title'], 80)))
                ->danger()
                ->send();

            return;
        }

        $previousWpPostId = (int) ($this->record->wordpressLink?->wp_post_id ?? 0);
        if ($previousWpPostId === $wpPostId) {
            $this->manualWpPostId = (string) $wpPostId;
            $this->manualWpPostLookup = [
                'wp_post_id' => $wpPostId,
                'duplicates' => $duplicates,
                'remote_found' => null,
                'self_match' => true,
                'message' => 'WP ID này đã thuộc chính bài hiện tại. Không phải bài trùng và không cần nối lại.',
            ];

            Notification::make()
                ->title('WP ID đã thuộc bài hiện tại')
                ->body('Article hiện tại đang liên kết với WordPress #'.$wpPostId.'.')
                ->success()
                ->send();

            return;
        }

        $this->record->forceFill(['wp_post_id' => $wpPostId])->save();
        $this->record->refresh();
        $this->record->loadMissing('articleMetas', 'site');

        $post = app(WordPressArticleContentService::class)
            ->fetchFromWordPress($this->record, importFaqs: false);

        if ($post === []) {
            $this->record->forceFill([
                'wp_post_id' => $previousWpPostId > 0 ? $previousWpPostId : null,
            ])->save();
            $this->record->refresh();
            $this->manualWpPostId = $previousWpPostId > 0 ? (string) $previousWpPostId : '';

            RuntimeLogger::warning('article_editor.manual_wp_id_link_failed', [
                'article_id' => (int) $this->record->getKey(),
                'site_id' => (int) ($this->record->site_id ?? 0),
                'wp_post_id' => $wpPostId,
            ]);

            Notification::make()
                ->title('Không nối được WP ID')
                ->body('Không fetch được bài WordPress với ID này. Kiểm tra đúng domain, token bridge và WP ID rồi thử lại.')
                ->danger()
                ->send();

            return;
        }

        app(WordPressArticleContentService::class)
            ->refreshSlugAndPermalinkFromWordPress($this->record);

        $this->record->refresh();
        $this->record->loadMissing('articleMetas', 'site');
        $this->manualWpPostId = (string) $wpPostId;
        $this->manualWpPostLookup = [
            'wp_post_id' => $wpPostId,
            'duplicates' => $this->manualWpPostIdDuplicateRows($wpPostId),
            'remote_found' => true,
            'message' => 'Đã nối WP ID thành công.',
        ];
        $this->wordpressMetadataStale = false;
        $this->articleSlug = trim((string) ($this->record->slug ?? $this->articleSlug));
        $this->dispatchGoogleSerpPreviewUpdated();

        RuntimeLogger::info('article_editor.manual_wp_id_linked', [
            'article_id' => (int) $this->record->getKey(),
            'site_id' => (int) ($this->record->site_id ?? 0),
            'wp_post_id' => $wpPostId,
            'previous_wp_post_id' => $previousWpPostId > 0 ? $previousWpPostId : null,
        ]);

        Notification::make()
            ->title('Đã nối WP ID')
            ->body('Article đã liên kết lại với bài WordPress #'.$wpPostId.'.')
            ->success()
            ->send();
    }

    private function normalizeManualWpPostId(): int
    {
        return (int) preg_replace('/\D+/', '', trim($this->manualWpPostId));
    }

    /**
     * @return list<array{id: int, title: string, edit_url: string, current: bool}>
     */
    private function manualWpPostIdDuplicateRows(int $wpPostId): array
    {
        if ($wpPostId <= 0) {
            return [];
        }

        $currentId = (int) $this->record->getKey();
        $siteId = (int) ($this->record->site_id ?? 0);

        return SeoArticle::query()
            ->where('site_id', $siteId)
            ->whereWpPostId($wpPostId)
            ->orderByRaw('id = ? desc', [$currentId])
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'title'])
            ->map(static fn (SeoArticle $article): array => [
                'id' => (int) $article->id,
                'title' => trim((string) $article->title) !== '' ? (string) $article->title : 'Untitled',
                'edit_url' => ArticleResource::getUrl('edit', ['record' => $article]),
                'current' => (int) $article->id === $currentId,
            ])
            ->values()
            ->all();
    }

    public function getStatusLabel(): string
    {
        return match ($this->articleStatus) {
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'private' => 'Private',
            default => 'Draft',
        };
    }

    public function getPublishedAtLabel(): ?string
    {
        $publishedAt = $this->resolvePublishAtForEditor();
        if ($publishedAt === null) {
            return null;
        }

        return $publishedAt->timezone(SeoDisplayTimezone::name())->format('d/m/Y H:i');
    }

    public function getPublishWhenLabel(): string
    {
        if ((string) $this->articleStatus !== 'scheduled') {
            return '';
        }

        $publishedAt = $this->resolvePublishAtForEditor();
        if ($publishedAt === null) {
            return 'Not scheduled';
        }

        return $this->formatWpScheduleLabel($publishedAt);
    }

    public function shouldShowPublishScheduleRow(): bool
    {
        return app(ArticleScheduleReconcileService::class)
            ->shouldShowScheduleLabel((string) $this->articleStatus);
    }

    public function getPublishedAtSidebarLabel(): ?string
    {
        if (! app(ArticleScheduleReconcileService::class)
            ->shouldShowPublishedAtLabel((string) $this->articleStatus, $this->record->publishingState?->published_at)) {
            return null;
        }

        /** @var Carbon $publishedAt */
        $publishedAt = $this->record->publishingState?->published_at;

        return SeoDisplayTimezone::formatScheduleLabel($publishedAt);
    }

    public function getVisibilityLabel(): string
    {
        return $this->visibility === 'private' ? 'Private' : 'Public';
    }

    public function getStatusLabelForPublishBox(): string
    {
        return match ($this->articleStatus) {
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'private' => 'Private',
            default => 'Draft',
        };
    }

    public function applyPublishBoxFromClient(
        string $postType,
        string $status,
        string $visibility,
        string $publishDay,
        string $publishMonth,
        string $publishYear,
        string $publishHour,
        string $publishMinute,
    ): void {
        $this->articlePostType = SeoProjectTask::normalizePostType($postType);
        $this->articleStatus = in_array($status, ['draft', 'published', 'scheduled', 'private'], true)
            ? $status
            : 'draft';
        $this->visibility = $visibility === 'private' ? 'private' : 'public';
        $this->publishDay = $publishDay;
        $this->publishMonth = $publishMonth;
        $this->publishYear = $publishYear;
        $this->publishHour = $publishHour;
        $this->publishMinute = $publishMinute;

        if ($this->supportsProductGallery()) {
            $this->productGallery = app(ArticleMediaLocalService::class)->resolveProductAlbum($this->record);
            $this->featuredImageUrl = $this->productGallery[0]['url'] ?? $this->featuredImageUrl;
        } else {
            $this->productGallery = [];
        }

        $this->skipRender();
    }

    /**
     * @param  list<array{id?: int, url?: string, wp_attachment_id?: int, seo_media_id?: int}>  $items
     * @return list<array{id: int, url: string}>
     */
    public function persistProductAlbumFromClient(array $items): array
    {
        if (! $this->supportsProductGallery()) {
            $this->skipRender();

            return [];
        }

        $user = auth()->user();
        $sessions = app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService::class);
        if ($user instanceof \App\Models\User && $sessions->findActiveSession($this->record) !== null) {
            $sessions->assertOwningActiveSessionForWrite(
                $this->record,
                $user,
                $this->editorSessionId,
                null,
            );
        }

        $localMedia = app(ArticleMediaLocalService::class);
        $siteId = (int) ($this->record->site_id ?? 0);
        $album = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
            $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? $item['id'] ?? 0));
            $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;

            if ($localRefId <= 0) {
                $localRefId = $localMedia->resolveLocalRefIdFromImageUrl($siteId, $url);
            }

            $album[] = [
                'id' => $localRefId,
                'url' => $url,
            ];
        }

        $this->productGallery = $localMedia->saveProductAlbumLocal($this->record, $album);
        $this->featuredImageUrl = $this->productGallery[0]['url'] ?? $this->featuredImageUrl;
        $this->record->refresh();
        app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorMediaSnapshotService::class)
            ->bumpVersion($this->record);
        $this->syncProductGalleryToEditor();

        $this->skipRender();

        return $this->productGallery;
    }

    /**
     * @param  array{url?: string, wp_attachment_id?: int, wpAttachmentId?: int, seo_media_id?: int, seoMediaId?: int}  $item
     */
    public function persistFeaturedImageFromClient(array $item): void
    {
        if ($this->supportsProductGallery()) {
            $this->skipRender();

            return;
        }

        $user = auth()->user();
        $sessions = app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService::class);
        if ($user instanceof \App\Models\User && $sessions->findActiveSession($this->record) !== null) {
            $sessions->assertOwningActiveSessionForWrite(
                $this->record,
                $user,
                $this->editorSessionId,
                null,
            );
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            $this->skipRender();

            return;
        }

        $wpAttachmentId = max(0, (int) ($item['wp_attachment_id'] ?? $item['wpAttachmentId'] ?? 0));
        $seoMediaId = max(0, (int) ($item['seo_media_id'] ?? $item['seoMediaId'] ?? 0));
        $localRefId = $wpAttachmentId > 0 ? $wpAttachmentId : $seoMediaId;
        $localMedia = app(ArticleMediaLocalService::class);

        if ($localRefId <= 0) {
            $localRefId = $localMedia->resolveLocalRefIdFromImageUrl(
                (int) ($this->record->site_id ?? 0),
                $url,
            );
        }

        if ($localRefId > 0) {
            $localMedia->applyFeaturedLocal($this->record, $localRefId, $url);
            $this->featuredImageUrl = $url;
            $this->record->refresh();
            app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorMediaSnapshotService::class)
                ->bumpVersion($this->record);
        }

        $this->skipRender();
    }

    protected function shouldDisableSeoFormSave(): bool
    {
        return $this->articleHeavyActionBusy;
    }

    public function requestSaveArticle(): void
    {
        if ($this->articleHeavyActionBusy) {
            return;
        }

        $this->beginHeavyArticleAction('save');
        $this->pendingEditorCollectTarget = 'save';
        $this->dispatch('flush-article-faqs');
    }

    public function reconcileObservedWordPressState(): void
    {
        $record = $this->record;
        if (! $record instanceof SeoArticle) {
            return;
        }

        $result = app(\Omnichannel\Addons\Publishing\Services\Publishing\ObservedWordPressStatusReconcileAction::class)
            ->forArticle($record);
        $record->unsetRelation('wordpressLink');
        $record->load('wordpressLink');
        Notification::make()
            ->title('Kiểm tra lại trạng thái')
            ->body((string) ($result['message'] ?? 'Đã lưu observed WordPress state.'))
            ->success()
            ->send();
    }

    public function requestSyncToWordPress(): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        if ($this->articleHeavyActionBusy) {
            return;
        }

        $this->beginHeavyArticleAction('sync');
        $this->pendingEditorCollectTarget = 'sync';
        $this->dispatch('flush-article-faqs');
    }

    /**
     * Lưu / đồng bộ gói: publish box, danh mục, FAQ, media draft và nội dung — một round-trip Livewire.
     *
     * @param  array{
     *     action?: string,
     *     html?: string,
     *     seo_analysis?: array<string, mixed>|null,
     *     faqs?: list<array<string, mixed>>|null,
     *     publish_box?: array<string, mixed>|null,
     *     category_ids?: list<int>|null,
     *     featured_image?: array<string, mixed>|null,
     *     product_album?: list<array<string, mixed>>|null,
     * }  $bundle
     */
    public function executeHeavyArticleAction(array $bundle): void
    {
        if ($this->articleHeavyActionBusy) {
            return;
        }

        $action = ($bundle['action'] ?? 'save') === 'sync' ? 'sync' : 'save';
        if ($action === 'sync') {
            abort_if(SeoAccessControl::isContentManager(), 403);
        }

        $this->beginHeavyArticleAction($action);
        $this->pendingEditorCollectTarget = null;

        try {
            $publishBox = $bundle['publish_box'] ?? null;
            if (is_array($publishBox)) {
                $this->applyPublishBoxFromClient(
                    (string) ($publishBox['post_type'] ?? $this->articlePostType),
                    (string) ($publishBox['status'] ?? $this->articleStatus),
                    (string) ($publishBox['visibility'] ?? $this->visibility),
                    (string) ($publishBox['publish_day'] ?? $this->publishDay),
                    (string) ($publishBox['publish_month'] ?? $this->publishMonth),
                    (string) ($publishBox['publish_year'] ?? $this->publishYear),
                    (string) ($publishBox['publish_hour'] ?? $this->publishHour),
                    (string) ($publishBox['publish_minute'] ?? $this->publishMinute),
                );
            }

            $categoryIds = $bundle['category_ids'] ?? null;
            if (is_array($categoryIds)) {
                $this->applyArticleCategoriesFromClient($categoryIds);
            }

            $faqs = $bundle['faqs'] ?? null;
            if (
                is_array($faqs)
                && ! $this->shouldSkipMalformedFaqsBundleSave($faqs)
                && ! $this->shouldSkipUnhydratedEmptyFaqsBundleSave($faqs, $bundle)
            ) {
                $this->saveArticleFaqsInline($faqs);
            }

            $featuredImage = $bundle['featured_image'] ?? null;
            if (is_array($featuredImage) && trim((string) ($featuredImage['url'] ?? '')) !== '') {
                $this->persistFeaturedImageFromClient($featuredImage);
            }

            $productAlbum = $bundle['product_album'] ?? null;
            if (is_array($productAlbum)) {
                $this->persistProductAlbumFromClient($productAlbum);
            }

            $html = (string) ($bundle['html'] ?? '');
            $seoAnalysis = is_array($bundle['seo_analysis'] ?? null) ? $bundle['seo_analysis'] : null;

            if ($action === 'sync') {
                // Các bước WordPress chạy tuần tự từ JS (__seoRunWordPressPhasedSync).
                return;
            } else {
                $this->persistArticleLocal($html, $seoAnalysis);
            }
        } catch (\Throwable $exception) {
            $this->cancelHeavyArticleAction();

            throw $exception;
        }
    }

    /**
     * Payload FAQ lỗi từ sidebar link ({text,index}) — không ghi đè FAQ đã có trên DB.
     *
     * @param  list<mixed>  $faqs
     */
    private function shouldSkipMalformedFaqsBundleSave(array $faqs): bool
    {
        foreach ($faqs as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (
                array_key_exists('text', $row)
                && ! array_key_exists('answer', $row)
                && ! array_key_exists('question', $row)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Phase 2 lazy FAQ: faqs:[] + faqs_source none/panel trống → không wipe DB.
     *
     * @param  list<mixed>  $faqs
     * @param  array<string, mixed>  $bundle
     */
    private function shouldSkipUnhydratedEmptyFaqsBundleSave(array $faqs, array $bundle): bool
    {
        if ($faqs !== []) {
            return false;
        }

        $source = strtolower(trim((string) ($bundle['faqs_source'] ?? '')));
        if ($source === 'editor') {
            return false;
        }

        return $this->record->faqs()->exists();
    }

    private function beginHeavyArticleAction(string $action): void
    {
        $this->articleHeavyActionBusy = true;
        $this->articleHeavyAction = $action;
        $this->dispatch('article-autosave-lock', reason: 'article-heavy-action', locked: true);
        $this->dispatch('article-wordpress-sync-lock', action: $action);
    }

    private function finishHeavyArticleActionWithReload(bool $clearLocalState = false): void
    {
        $action = $this->articleHeavyAction ?? 'save';
        $articleId = (int) $this->record->getKey();
        $siteId = (int) ($this->record->site_id ?? 0);

        // Bypass editor beforeunload guard — intentional code reload after heavy action.
        $prefix = 'window.__SEO_EDITOR_EXITING__=true;'
            .'window.__seoMarkIntentionalEditorClose?.();'
            ."window.__seoArticleHeavyActionOverlay?.show('{$action}', { persistUntilUnload: true });";

        if ($clearLocalState) {
            $this->js(
                $prefix
                ."window.__seoClearArticleLocalState?.({$articleId}, {$siteId});"
                .'window.location.reload();'
            );

            return;
        }

        $this->js(
            $prefix
            .'window.location.reload();'
        );
    }

    public function cancelHeavyArticleAction(): void
    {
        $this->articleHeavyActionBusy = false;
        $this->articleHeavyAction = null;
        $this->dispatch('article-autosave-lock', reason: 'article-heavy-action', locked: false);
        $this->dispatch('article-wordpress-sync-unlock');
    }

    /** Dự phòng khi flush FAQ không gọi được saveArticleFaqs (timeout phía client). */
    public function finalizePendingEditorCollect(): void
    {
        if ($this->pendingEditorCollectTarget === null) {
            return;
        }

        $target = $this->pendingEditorCollectTarget;
        $this->pendingEditorCollectTarget = null;
        $this->dispatch('collect-editor-html', target: $target);
    }

    public function getArticlePreviewUrl(): string
    {
        return route('seo.articles.preview', ['article' => $this->record->id]);
    }

    public function getReviewStatusLabel(): string
    {
        $approved = app(ArticleReviewService::class)->isCanonicallyApproved($this->record);

        return $approved
            ? __('seo-content-ai::filament.article_list.reviewed')
            : __('seo-content-ai::filament.article_list.not_reviewed');
    }

    public function siteHasPolylang(): bool
    {
        $this->record->loadMissing('site');

        return app(SitePolylangService::class)->isPolylangEnabledForSite($this->record->site);
    }

    public function getArticleLanguageLabel(): string
    {
        return app(ArticlePolylangSyncService::class)->currentLanguageLabel($this->record);
    }

    /**
     * @return list<array{lang: string, label: string, flag: string, article_id: int|null, wp_post_id: int|null, edit_url: string|null, status: string}>
     */
    public function getTranslationConnections(): array
    {
        if (! $this->siteHasPolylang()) {
            return [];
        }

        return app(ArticlePolylangSyncService::class)->translationConnectionsForArticle($this->record);
    }

    public function importMissingTranslation(string $targetLang): void
    {
        $result = app(ArticlePolylangSyncService::class)->importTranslationForLanguage($this->record, $targetLang);

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title('Import bản dịch')
                ->body((string) ($result['message'] ?? 'Thất bại.'))
                ->danger()
                ->send();

            return;
        }

        $editUrl = trim((string) ($result['edit_url'] ?? ''));
        if ($editUrl !== '') {
            $this->redirect($editUrl);

            return;
        }

        Notification::make()
            ->title('Import bản dịch')
            ->body((string) ($result['message'] ?? 'Đã import.'))
            ->success()
            ->send();
    }

    public function requestTranslationGeneration(string $targetLang): void
    {
        $label = app(SitePolylangService::class)->languageLabel(
            $targetLang,
            $this->record->site,
        );

        Notification::make()
            ->title('Tạo bản dịch')
            ->body('Chưa có bản «'.$label.'» trên WordPress. Tạo bản dịch trong WP/Polylang hoặc chạy Quy trình SEO để sinh nội dung.')
            ->warning()
            ->send();
    }

    public function isDefaultLanguageArticle(): bool
    {
        $this->record->loadMissing('site');
        $lang = trim((string) ($this->record->language ?? 'vi'));

        return app(SitePolylangService::class)->isDefaultLanguage(
            $lang,
            $this->record->site,
        );
    }

    public function canQuickTranslateLinkedArticle(): bool
    {
        return app(SeoCreateArticleSettingsService::class)->getTranslateArticlePromptId() !== null;
    }

    public function requestQuickTranslate(string $targetLang, ?int $targetArticleId = null): void
    {
        if (! $this->canQuickTranslateLinkedArticle()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.translate_failed'))
                ->body(__('seo-content-ai::filament.article_edit.translate_no_prompt'))
                ->warning()
                ->send();

            return;
        }

        if (! $this->isDefaultLanguageArticle()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.translate_failed'))
                ->body(__('seo-content-ai::filament.article_edit.translate_not_default_language'))
                ->warning()
                ->send();

            return;
        }

        $targetLang = trim($targetLang);
        if ($targetLang === '' || $targetArticleId === null || $targetArticleId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.translate_failed'))
                ->body(__('seo-content-ai::filament.article_edit.translate_invalid_target'))
                ->danger()
                ->send();

            return;
        }

        if ($this->articleHeavyActionBusy) {
            return;
        }

        $this->pendingQuickTranslateLang = $targetLang;
        $this->pendingQuickTranslateTargetArticleId = $targetArticleId;
        $this->pendingEditorCollectTarget = 'quick-translate';
        $this->dispatch('collect-editor-html', target: 'quick-translate');
    }

    public function quickTranslateLinkedArticle(string $editorHtml = ''): void
    {
        $targetLang = trim((string) ($this->pendingQuickTranslateLang ?? ''));
        $targetArticleId = (int) ($this->pendingQuickTranslateTargetArticleId ?? 0);
        $this->pendingQuickTranslateLang = null;
        $this->pendingQuickTranslateTargetArticleId = null;
        $this->pendingEditorCollectTarget = null;

        if ($this->articleHeavyActionBusy) {
            return;
        }

        $this->beginHeavyArticleAction('quick-translate');

        try {
            $targetArticle = SeoArticle::query()->find($targetArticleId);
            if (! $targetArticle instanceof SeoArticle) {
                throw new \InvalidArgumentException(
                    __('seo-content-ai::filament.article_edit.translate_invalid_target'),
                );
            }

            $result = app(ArticleQuickTranslateService::class)->translateLinkedArticle(
                $this->record,
                $targetArticle,
                $editorHtml,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->cancelHeavyArticleAction();

            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.translate_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->cancelHeavyArticleAction();

        $editUrl = trim((string) ($result['edit_url'] ?? ''));
        $targetLanguage = trim((string) ($result['target_language'] ?? $targetLang));

        if ($editUrl !== '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.translate_success'))
                ->body(__('seo-content-ai::filament.article_edit.translate_success_body', [
                    'language' => $targetLanguage,
                ]))
                ->success()
                ->send();

            $this->redirect($editUrl);

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_edit.translate_success'))
            ->body(__('seo-content-ai::filament.article_edit.translate_success_body', [
                'language' => $targetLanguage,
            ]))
            ->success()
            ->send();
    }

    /**
     * @deprecated Giữ cho các call site cũ (nếu còn) — UI hiện dùng {@see self::getArticleReviewBootstrap()}
     * (available_actions từ ArticleReviewService) để quyết định hiện nút/badge nào.
     */
    public function canToggleArticleReview(): bool
    {
        $user = auth()->user();
        if (! $user instanceof \App\Models\User) {
            return false;
        }

        return app(ArticleReviewService::class)->availableActions($this->record, $user) !== [];
    }

    /**
     * @deprecated Trạng thái "đã gửi duyệt" giờ là `review_status !== draft` (article-level),
     * không còn phụ thuộc project status qua SeoProjectApprovalService.
     */
    public function contentManagerSubmittedForReview(): bool
    {
        return app(ArticleReviewService::class)->resolveStatus($this->record) !== ArticleReviewStatus::Draft;
    }

    /**
     * Bootstrap dữ liệu review cho blade `article-editor-page-actions` (badge + split-button
     * "Duyệt bài"). Nguồn sự thật duy nhất là {@see ArticleReviewService::toApiPayload()} —
     * giống hệt payload REST `seo.articles.review-actions.show/store`.
     *
     * @return array{review_status: string, badge_label: string, available_actions: array<int, array<string, string>>, latest_review: array<string, mixed>|null}
     */
    public function getArticleReviewBootstrap(): array
    {
        $user = auth()->user();
        if (! $user instanceof \App\Models\User) {
            $status = app(ArticleReviewService::class)->resolveStatus($this->record);

            return [
                'review_status' => $status->value,
                'badge_label' => (string) __('seo-content-ai::filament.article_review.badge.'.$status->value),
                'available_actions' => [],
                'latest_review' => null,
            ];
        }

        $payload = app(ArticleReviewService::class)->toApiPayload($this->record, $user);
        $status = (string) $payload['data']['review_status'];

        return [
            'review_status' => $status,
            'badge_label' => (string) __('seo-content-ai::filament.article_review.badge.'.$status),
            'available_actions' => $payload['data']['available_actions'],
            'latest_review' => $payload['data']['latest_review'],
        ];
    }

    /**
     * Livewire fallback cho Article Review action (progressive enhancement — UI chính dùng
     * fetch() trực tiếp tới `seo.articles.review-actions.store`, xem
     * `article-editor-page-actions.blade.php`).
     */
    public function performArticleReviewAction(string $action, ?string $note = null): void
    {
        $user = auth()->user();
        if (! $user instanceof \App\Models\User) {
            abort(403);
        }

        $actionType = ArticleReviewActionType::tryFromString($action);
        if (! $actionType instanceof ArticleReviewActionType) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_review.errors.invalid_transition'))
                ->danger()
                ->send();

            return;
        }

        try {
            $review = app(ArticleReviewService::class)->performAction($this->record, $user, $actionType, $note);
        } catch (ArticleReviewException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->record->refresh();

        Notification::make()
            ->title((string) __('seo-content-ai::filament.article_review.success.'.$review->action_type))
            ->success()
            ->send();
    }

    public function getReviewedAtLabel(): ?string
    {
        $reviewedAt = $this->record->reviewed_at;

        return $reviewedAt instanceof Carbon
            ? $reviewedAt->timezone(config('app.timezone'))->format('d/m/Y H:i')
            : null;
    }

    public function getVirtualCommentsCount(): int
    {
        return count($this->getVirtualReviewsPayload());
    }

    public function getReviewsCountForEditor(): int
    {
        if ($this->reviewsCountForEditor !== null) {
            return max(0, (int) $this->reviewsCountForEditor);
        }

        $this->reviewsCountForEditor = count($this->getVirtualReviewsPayload());

        return $this->reviewsCountForEditor;
    }

    public function canGenerateQuickPostReviews(): bool
    {
        return app(SeoCreateArticleSettingsService::class)->getPostReviewTaskId() !== null;
    }

    /**
     * @return list<array{author: string, content: string, rating?: int|null, date: string}>
     */
    public function generateQuickPostReviews(): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        if (! $this->canGenerateQuickPostReviews()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_failed'))
                ->body(__('seo-content-ai::filament.article_list.quick_create_reviews_configure_hint'))
                ->warning()
                ->send();

            return $this->getVirtualReviewsPayload();
        }

        if ($this->getReviewsCountForEditor() > 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_failed'))
                ->body(__('seo-content-ai::filament.article_list.quick_create_reviews_already_exists'))
                ->warning()
                ->send();

            return $this->getVirtualReviewsPayload();
        }

        $this->quickReviewsJobPending = true;

        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }

            $result = app(ArticleQuickPostReviewService::class)->runForArticle($this->record->fresh());

            return $this->applyQuickPostReviewsResult($result);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return $this->getVirtualReviewsPayload();
        } finally {
            $this->quickReviewsJobPending = false;
        }
    }

    /**
     * @param  array{success?: bool, message?: string, created_count?: int|null}  $result
     * @return list<array{author: string, content: string, rating?: int|null, date: string}>
     */
    private function applyQuickPostReviewsResult(array $result): array
    {
        $this->record->refresh();

        $reviews = $this->getVirtualReviewsPayload();
        $this->reviewsCountForEditor = count($reviews);
        $this->dispatch('virtual-reviews-updated', reviews: $reviews);

        $success = (bool) ($result['success'] ?? false);
        $count = (int) ($result['created_count'] ?? count($reviews));
        $automationEnabled = (bool) ($result['automation_enabled'] ?? false);
        $hasWpPostId = (bool) ($result['has_wp_post_id'] ?? ((int) ($this->record->wordpressLink?->wp_post_id ?? 0) > 0));

        if ($success) {
            if (! $automationEnabled) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_success'))
                    ->body("Đã tạo {$count} review nhưng Automation đăng review đang tắt. Review hiện được lưu cục bộ.")
                    ->warning()
                    ->send();
            } elseif (! $hasWpPostId) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_success'))
                    ->body("Đã tạo {$count} review. Review sẽ tự động được đăng sau khi bài viết đồng bộ WordPress.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_success'))
                    ->body("Đã tạo {$count} review. Hệ thống sẽ tự động đăng lên WordPress trong vòng 5 phút.")
                    ->success()
                    ->send();
            }
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_failed'))
                ->body(trim((string) ($result['message'] ?? '')) ?: '')
                ->danger()
                ->send();
        }

        return $reviews;
    }

    /**
     * @return list<array{author: string, content: string, rating?: int|null, date: string}>
     */
    #[On('request-virtual-reviews-refresh')]
    public function refreshVirtualReviewsForEditor(): array
    {
        $this->record->refresh();
        $reviews = $this->getVirtualReviewsPayload();
        $this->reviewsCountForEditor = count($reviews);
        $this->dispatch('virtual-reviews-updated', reviews: $reviews);

        return $reviews;
    }

    public function pollQuickReviewsJob(): void
    {
        if (! $this->quickReviewsJobPending) {
            return;
        }

        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            return;
        }

        $cacheKey = 'seo_article_reviews_ready:'.(int) $this->record->id.':'.$userId;
        if (! Cache::pull($cacheKey)) {
            return;
        }

        $this->quickReviewsJobPending = false;
        $this->record->refresh();

        $reviews = $this->getVirtualReviewsPayload();
        $this->dispatch('virtual-reviews-updated', reviews: $reviews);

        Notification::make()
            ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_success'))
            ->body(__('seo-content-ai::filament.article_list.virtual_comments_count', [
                'count' => count($reviews),
            ]))
            ->success()
            ->send();
    }

    /**
     * @return list<array{author: string, content: string, rating?: int|null, date: string}>
     */
    public function getVirtualReviewsPayload(): array
    {
        return app(\Omnichannel\Addons\Commerce\Services\ProductReview\ArticleProductReviewStoreService::class)
            ->listForEditor($this->record);
    }

    /**
     * @deprecated UI blade giờ gọi thẳng REST `seo.articles.review-actions.store` (fetch)
     * hoặc {@see self::performArticleReviewAction()}. Giữ làm alias cho call site cũ.
     */
    public function toggleArticleReview(): void
    {
        $this->approveArticle();
    }

    /**
     * @deprecated Alias — chạy hành động review kế tiếp (submit_review/approve/archive) theo
     * {@see ArticleReviewService::availableActions()} thay vì phân nhánh role thủ công.
     */
    public function approveArticle(): void
    {
        ArticleResource::runApproveArticleAction($this->record);
        $this->record->refresh();
    }

    /** @deprecated Reviewed must not auto-create product reviews — WP/automation owns that flow. */
    private function runProductReviewWorkflowAfterApproval(): void
    {
        $article = $this->record->fresh();
        if (! $article instanceof SeoArticle
            || ! ArticlePostTypeResolver::isProduct($article)
            || ArticlePostTypeResolver::isTerm($article)
            || $this->getVirtualCommentsCount() > 0
            || ! $this->canGenerateQuickPostReviews()) {
            return;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            $result = app(ArticleQuickPostReviewService::class)->runForArticle($article);
            $this->applyQuickPostReviewsResult($result);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_list.quick_create_reviews_failed'))
                ->body($exception->getMessage())
                ->warning()
                ->send();
        }
    }

    /**
     * Fetch slug + permalink mới từ WordPress sau khi đồng bộ slug.
     */
    private function refreshArticleSlugFromWordPressAfterSync(): void
    {
        $refresh = app(WordPressArticleContentService::class)
            ->refreshSlugAndPermalinkFromWordPress($this->record->fresh());

        if ($refresh['slug'] !== '') {
            $this->articleSlug = (string) $refresh['slug'];
        }

        $this->record->refresh();
        $this->record->loadMissing('articleMetas');

        // Phase 2B: React owns immediate analysis — nudge slug only; no shadow seo-analyze-result.
        $this->js(sprintf(
            'window.dispatchEvent(new CustomEvent("seo-editor-slug-updated", { detail: %s }))',
            json_encode([
                'slug' => $this->articleSlug,
                'article_slug' => $this->articleSlug,
                'permalink' => trim($this->getDisplayPermalink()),
                'wordpress_permalink' => trim($this->getObservedWordPressPermalink()),
                'permalink_base' => rtrim($this->getPermalinkBase(), '/'),
                'permalink_suffix' => $this->getPermalinkSuffix(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));
    }

    /**
     * Cập nhật editor + meta ảnh sau khi body/wp_post_content đã có URL WordPress.
     */
    private function refreshEditorAfterWordPressSync(): void
    {
        $this->record->refresh();
        $this->record->loadMissing('articleMetas');

        $syncedHtml = trim((string) ($this->record->body ?? ''));
        if ($syncedHtml === '') {
            $syncedHtml = trim((string) ($this->record->articleMetas
                ->firstWhere('meta_key', 'wp_post_content')?->meta_value ?? ''));
        }
        if ($syncedHtml === '') {
            $syncedHtml = trim(app(WordPressArticleContentService::class)->resolveEditorHtml($this->record));
        }

        if ($syncedHtml !== '') {
            app(ArticlePostImagesService::class)->syncFromHtml($this->record, $syncedHtml);
            $this->bootstrapEditorHtml = $syncedHtml;
        }

        $this->featuredImageUrl = app(WordPressArticleContentService::class)->resolveFeaturedImageUrl($this->record);
        $this->productGallery = $this->supportsProductGallery()
            ? app(ArticleMediaLocalService::class)->resolveProductAlbum($this->record)
            : [];
        if ($this->supportsProductGallery()) {
            $this->featuredImageUrl = $this->productGallery[0]['url'] ?? $this->featuredImageUrl;
        }

        $this->dispatch(
            'article-faqs-extracted',
            faqs: app(ArticleFaqEditorService::class)->payloadForArticle($this->record),
            editorHtml: $syncedHtml !== '' ? $syncedHtml : $this->bootstrapEditorHtml,
        );
        $this->dispatch('article-post-images-synced', images: $this->getEditorImagesPayload());
        $this->dispatch('article-supplemental-images-synced', images: $this->getEditorSupplementalImagesPayload());
    }

    /**
     * Lưu vào Laravel (không đẩy WordPress).
     * User-facing body write bắt buộc owning editor session — không direct model update.
     */
    public function persistArticleLocal(string $html, ?array $seoAnalysis = null): void
    {
        try {
            $html = $this->persistBodyViaSessionAwarePath($html, $seoAnalysis, notify: true);
            if ($html === null) {
                return;
            }
        } catch (ArticleEditorSessionException $exception) {
            $this->notifyEditorSessionWriteBlocked($exception);
            $this->cancelHeavyArticleAction();

            return;
        } catch (\Throwable $exception) {
            $this->cancelHeavyArticleAction();

            throw $exception;
        }
    }

    /**
     * Session-aware body persist used by Sync WP local phase / FAQ inject.
     * Returns null when write blocked (caller must abort).
     */
    private function persistArticleLocalSilent(string $html): ?string
    {
        try {
            return $this->persistBodyViaSessionAwarePath($html, null, notify: false);
        } catch (ArticleEditorSessionException $exception) {
            RuntimeLogger::warning('seo.editor.livewire_silent_persist_blocked', [
                'article_id' => (int) $this->record->getKey(),
                'error' => $exception->errorCode,
            ]);

            return null;
        }
    }

    /**
     * Canonical Livewire body write: session lock + ArticleEditorPersistService (no direct body update).
     *
     * @return string|null Written HTML, or null when blocked/rejected.
     *
     * @throws ArticleEditorSessionException
     */
    private function persistBodyViaSessionAwarePath(string $html, ?array $seoAnalysis, bool $notify): ?string
    {
        $user = auth()->user();
        if (! $user instanceof \App\Models\User) {
            if ($notify) {
                Notification::make()
                    ->title('Không lưu được nội dung')
                    ->body('Phiên đăng nhập không hợp lệ.')
                    ->warning()
                    ->send();
                $this->cancelHeavyArticleAction();
            }

            return null;
        }

        app(ArticleEditorSessionService::class)->assertOwningActiveSessionForWrite(
            $this->record,
            $user,
            $this->editorSessionId,
            $this->expectedDocumentVersion,
        );

        $html = app(ArticleEditorHtmlSanitizeService::class)->stripTransientEditorMarkup($html);
        $html = $this->guardArticleBodyBeforeSave($html);

        $persist = app(ArticleEditorPersistService::class);
        $rejectedEmpty = $persist->rejectUnhydratedEmptyPersist($this->record, $html);
        if ($rejectedEmpty !== null) {
            if ($notify) {
                Notification::make()
                    ->title('Nội dung chưa được đồng bộ')
                    ->body((string) ($rejectedEmpty['message'] ?? 'Đồng bộ từ WordPress trước khi lưu.'))
                    ->warning()
                    ->send();
                $this->cancelHeavyArticleAction();
            }

            return null;
        }

        if (strlen(trim($html)) < 50 && $this->articleHadSubstantialContent()) {
            if ($notify) {
                Notification::make()
                    ->title('Không lưu được nội dung')
                    ->body('Editor trả về nội dung rỗng. Hãy thử lại hoặc dùng Lấy từ WordPress / Restore trước khi lưu.')
                    ->warning()
                    ->send();
                $this->cancelHeavyArticleAction();
            }

            return null;
        }

        $faqSync = app(ArticleFaqBodySyncService::class)->extractFromBodyWhenMissing($this->record, $html);
        $html = $faqSync['body_html'];
        if ($faqSync['extracted']) {
            $this->dispatch('article-faqs-extracted', faqs: $faqSync['faqs'], editorHtml: $html);
        } else {
            $this->dispatchFaqExtractDebugIfPresent($faqSync['extract_debug'] ?? null);
        }

        $slug = Str::slug($this->articleSlug);
        $postType = SeoProjectTask::normalizePostType($this->articlePostType);
        $bundle = [
            'article_meta' => [
                'title' => trim($this->articleTitle),
                'slug' => $slug,
                'seo_meta_description' => trim($this->seoMetaDescription),
                'focus_keyword' => trim($this->focusKeyword),
            ],
            'publish_box' => [
                'post_type' => $postType,
                'status' => $this->articleStatus,
                'visibility' => $this->visibility ?? 'public',
                'publish_day' => (string) ($this->publishDay ?? ''),
                'publish_month' => (string) ($this->publishMonth ?? ''),
                'publish_year' => (string) ($this->publishYear ?? ''),
                'publish_hour' => (string) ($this->publishHour ?? ''),
                'publish_minute' => (string) ($this->publishMinute ?? ''),
            ],
            'expected_document_version' => $this->expectedDocumentVersion,
        ];

        $context = ArticleEditorSaveContext::fromBundle($this->record, $bundle);
        $writtenHtml = $persist->writeArticleRow($this->record, $context, $html);
        $persist->runAfterPersistSideEffects($this->record->fresh() ?? $this->record, $context, $writtenHtml);

        $this->persistArticlePostTypeMeta($postType);
        $this->articlePostType = $postType;
        $this->persistSeoMetaFields();
        $this->articleSlug = $slug;
        $this->syncPublishDatePartsFromRecord();
        $this->record->refresh();

        app(ArticleWordPressSyncFlagService::class)->markLocalEditPending($this->record);
        app(ArticleLastSavedTimestampService::class)->touchManualSaved($this->record);
        $this->captureArticleRevisionAfterSave($writtenHtml);

        app(ArticleAiHistoryApplicationService::class)->commitPendingOnSave(
            $this->record,
            (int) (auth()->id() ?? 0),
        );
        $this->refreshAiHistoryPendingBanner();

        app(SeoAnalyzerService::class)->analyzeSubmittedContent(
            $this->record->fresh(),
            $writtenHtml,
            trim($this->articleTitle),
            Str::slug($this->articleSlug) !== '' ? Str::slug($this->articleSlug) : trim((string) ($this->record->slug ?? '')),
            trim($this->seoMetaDescription) !== '' ? trim($this->seoMetaDescription) : null,
        );

        $this->expectedDocumentVersion = max(
            1,
            (int) (($this->record->fresh()?->document_version) ?? $this->expectedDocumentVersion ?? 1),
        );

        if ($notify) {
            Notification::make()
                ->title('Article saved')
                ->body('Content is saved only in SEO system. Use "Sync" to push to WordPress.')
                ->success()
                ->send();
            $this->cancelHeavyArticleAction();
        }

        return $writtenHtml;
    }

    private function notifyEditorSessionWriteBlocked(ArticleEditorSessionException $exception): void
    {
        Notification::make()
            ->title('Không lưu được nội dung')
            ->body($exception->getMessage())
            ->warning()
            ->send();

        $this->dispatch('seo-editor-session-write-blocked', [
            'error' => $exception->errorCode,
            'message' => $exception->getMessage(),
            'context' => $exception->context,
        ]);
    }

    /**
     * Bước 1: Lưu local + phân tích SEO (chưa gọi WordPress).
     *
     * @return array{success: bool, message: string, step: string, step_detail?: string}
     */
    public function syncWpPhaseSaveLocal(string $html, ?array $seoAnalysis = null): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        try {
            $syncService = app(WordPressArticleSyncService::class);
            $article = $this->record->fresh();
            $skipCheck = $syncService->shouldSkipSaveLocalPhase($article, $html, $seoAnalysis);

            if ($skipCheck['skip']) {
                $this->wpSyncContext = null;
                $this->wpSyncPrepared = null;
                $this->wpSyncDecoded = null;

                return [
                    'success' => true,
                    'message' => 'Bỏ qua lưu local — nội dung/SEO/ảnh đại diện chưa thay đổi.',
                    'step' => 'save_local',
                    'step_detail' => 'skipped=1, reason='.(string) ($skipCheck['reason'] ?? 'unchanged'),
                    'skipped' => true,
                ];
            }

            $html = $this->persistArticleLocalSilent($html);
            if ($html === null) {
                return [
                    'success' => false,
                    'message' => 'Không lưu local được — thiếu editor session hoặc bài đang bị khóa.',
                    'step' => 'save_local',
                ];
            }
            $slug = Str::slug($this->articleSlug);

            app(SeoAnalyzerService::class)->analyzeSubmittedContent(
                $this->record->fresh(),
                $html,
                trim($this->articleTitle),
                $slug !== '' ? $slug : trim((string) ($this->record->slug ?? '')),
                trim($this->seoMetaDescription) !== '' ? trim($this->seoMetaDescription) : null,
            );

            $syncService->storeLocalSaveFingerprint($this->record->fresh(), $html, $seoAnalysis);

            $this->wpSyncContext = null;
            $this->wpSyncPrepared = null;
            $this->wpSyncDecoded = null;

            return [
                'success' => true,
                'message' => 'Đã lưu bản nháp Laravel.',
                'step' => 'save_local',
                'step_detail' => 'article_id='.(int) $this->record->id,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'step' => 'save_local',
            ];
        }
    }

    /**
     * @deprecated Phased Livewire WP mutation removed — use ManualAutomationDispatcher via Sync button.
     *
     * @return array{success: bool, message: string, step: string}
     */
    public function syncWpPhasePreparePayload(): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        return [
            'success' => false,
            'message' => 'Phased WordPress sync đã cắt. Dùng nút Đồng bộ WordPress (Manual Automation).',
            'step' => 'prepare_payload',
        ];
    }

    /**
     * @deprecated
     *
     * @return array{success: bool, message: string, step: string}
     */
    public function syncWpPhaseEditorSync(): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        return [
            'success' => false,
            'message' => 'Phased WordPress sync đã cắt. Dùng nút Đồng bộ WordPress (Manual Automation).',
            'step' => 'editor_sync',
        ];
    }

    /**
     * @deprecated
     *
     * @return array{success: bool, message: string, step: string}
     */
    public function syncWpPhaseFinalize(): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        return [
            'success' => false,
            'message' => 'Phased WordPress sync đã cắt. Dùng nút Đồng bộ WordPress (Manual Automation).',
            'step' => 'finalize',
        ];
    }


    /**
     * Lưu Laravel rồi đẩy lên WordPress.
     */
    public function syncArticleToWordPress(string $html, ?array $seoAnalysis = null): void
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        try {
            $html = $this->persistArticleLocalSilent($html);
            if ($html === null) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.automation.wp_sync_blocked_title'))
                    ->body('Không lưu local được — thiếu editor session hoặc bài đang bị khóa.')
                    ->danger()
                    ->send();
                $this->cancelHeavyArticleAction();

                return;
            }

            $slug = Str::slug($this->articleSlug);
            app(SeoAnalyzerService::class)->analyzeSubmittedContent(
                $this->record->fresh(),
                $html,
                trim($this->articleTitle),
                $slug !== '' ? $slug : trim((string) ($this->record->slug ?? '')),
                trim($this->seoMetaDescription) !== '' ? trim($this->seoMetaDescription) : null,
            );

            $article = $this->record->fresh();
            $result = app(\Omnichannel\Addons\WordPress\Services\WordPressManualSyncService::class)
                ->publishNow(
                    $article,
                    auth()->user(),
                    'article_editor.sync_wordpress',
                    $this->resolveLivewireSeoPayloadForWordPress(),
                );

            if ($result['success'] ?? false) {
                $historyUrl = (string) ($result['automation_history_url'] ?? '');
                $status = (string) ($result['status'] ?? 'dispatched');
                Notification::make()
                    ->title($status === 'deduplicated'
                        ? __('seo-content-ai::filament.automation.gate.deduplicated_title')
                        : __('seo-content-ai::filament.automation.gate.dispatched_title'))
                    ->body((string) ($result['message'] ?? '')
                        .($historyUrl !== '' ? ' '.__('seo-content-ai::filament.automation.view_progress').': '.$historyUrl : ''))
                    ->info()
                    ->send();

                $this->cancelHeavyArticleAction();

                return;
            }

            Notification::make()
                ->title(__('seo-content-ai::filament.automation.wp_sync_blocked_title'))
                ->body((string) ($result['message'] ?? __('seo-content-ai::filament.automation.wp_sync_blocked_body')))
                ->danger()
                ->send();

            $this->cancelHeavyArticleAction();
        } catch (\Throwable $exception) {
            $this->cancelHeavyArticleAction();

            throw $exception;
        }
    }

    /**
     * Editor: «Viết lại toàn bộ bài hiện có» — existing_article → ArticleWritingExecutionService.
     * Không chạy Publish graph / Content Project orchestration.
     */
    public function queueEditorFullRewrite(?string $notes = null): void
    {
        abort_unless(SeoAccessControl::canAccessManagerFeatures(), 403);

        $article = $this->record;
        if (! $article instanceof SeoArticle) {
            Notification::make()
                ->title('Không thể viết lại bài')
                ->body('Thiếu bài viết.')
                ->danger()
                ->send();

            return;
        }

        $activeWriting = SeoProjectTask::query()
            ->where('article_id', (int) $article->getKey())
            ->where('status', SeoProjectTask::STATUS_WRITING)
            ->exists();
        if ($activeWriting) {
            Notification::make()
                ->title('Không thể viết lại bài')
                ->body('Bài đang thuộc Content Project run đang chạy. Chờ xong hoặc hủy run trước khi viết lại từ editor.')
                ->warning()
                ->send();

            return;
        }

        $this->pipelineRerunBusy = true;

        try {
            $context = app(TaskTestInputResolver::class)->resolveEditorFullRewrite(
                $article,
                $notes,
            );
            $writing = ArticleWritingInput::fromExistingArticleBody(
                bodyMarkdown: (string) ($context->variables['article_writing_raw_input'] ?? ''),
                title: trim((string) ($context->variables['post_title'] ?? '')),
                keyword: trim((string) ($context->variables['focus_keyword'] ?? '')),
                description: trim((string) ($context->variables['secondary_description'] ?? '')),
                articleId: (int) $article->getKey(),
            );

            $result = app(ArticleWritingExecutionService::class)->execute(
                $writing,
                new ArticleWritingExecutionContext(
                    mode: ArticleWritingExecutionMode::DirectGenerate,
                    promptOwnerType: ArticleWritingPromptOwnerType::SettingsBinding,
                    siteId: (int) ($article->site_id ?? 0),
                    taskContext: $context,
                    expectedUpdatedAt: $article->updated_at?->toIso8601String(),
                    baseVariables: $context->variables,
                ),
            );
        } catch (\Throwable $exception) {
            $this->pipelineRerunBusy = false;
            Notification::make()
                ->title('Không thể viết lại bài')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->pipelineRerunBusy = false;

        if (! $result->success) {
            Notification::make()
                ->title('Không thể viết lại bài')
                ->body($result->message)
                ->danger()
                ->send();

            return;
        }

        if ($result->persistStatus === ArticleWritingExecutionResult::PERSIST_IGNORED_STALE) {
            Notification::make()
                ->title('Đã bỏ qua kết quả cũ')
                ->body($result->message)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.projects.type_rewrite_editor'))
            ->body($result->message)
            ->success()
            ->send();

        $this->record->refresh();
        $this->record->load('articleMetas');
        $this->hydrateArticleState();
        $articleId = (int) $this->record->id;
        $this->js(sprintf(
            'window.dispatchEvent(new CustomEvent("seo-article-pipeline-rerun-completed", { detail: { articleId: %d } }))',
            $articleId,
        ));
    }

    public function queueArticlePipelineRerun(string $from = 'outline'): void
    {
        abort_unless(SeoAccessControl::canAccessManagerFeatures(), 403);

        $this->pipelineRerunWatching = true;
        $this->pipelineRerunBusy = true;
        $this->pipelineRerunStatus = ArticlePipelineRerunService::STATUS_RUNNING;
        $this->dispatch('close-article-pipeline-rerun-modal');

        $result = app(ArticlePipelineRerunService::class)->queue(
            $this->record,
            $from,
            auth()->id() !== null ? (int) auth()->id() : null,
        );

        $this->pipelineRerunUrl = isset($result['run_url']) ? (string) $result['run_url'] : null;
        $this->pipelineRerunMessage = (string) ($result['message'] ?? '');
        $this->pipelineRerunStatus = isset($result['status'])
            ? (string) $result['status']
            : ArticlePipelineRerunService::STATUS_FAILED;

        $this->pipelineRerunWatching = false;
        $this->pipelineRerunBusy = false;

        if (! ($result['success'] ?? false)) {
            Notification::make()
                ->title('Không thể chạy lại quy trình')
                ->body((string) ($result['message'] ?? 'Yêu cầu bị từ chối.'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Chạy lại quy trình thành công')
            ->body((string) ($this->pipelineRerunMessage ?: 'Đã áp dụng kết quả mới. Đang tải lại editor…'))
            ->success()
            ->persistent()
            ->send();

        $this->record->refresh();
        $this->record->load('articleMetas');
        $this->hydrateArticleState();
        $articleId = (int) $this->record->id;
        $this->js(sprintf(
            'window.dispatchEvent(new CustomEvent("seo-article-pipeline-rerun-completed", { detail: { articleId: %d } }))',
            $articleId,
        ));
    }

    public function refreshPipelineRerunStatus(): void
    {
        if (! $this->record instanceof SeoArticle) {
            return;
        }

        $service = app(ArticlePipelineRerunService::class);
        $article = $this->record->fresh() ?? $this->record;
        $payload = $service->statusPayload($article);

        // Meta queued/running còn sót từ lần trước (request đứt) → không khóa nút, đánh dấu failed.
        if (
            ! $this->pipelineRerunWatching
            && in_array($payload['status'] ?? null, [
                ArticlePipelineRerunService::STATUS_QUEUED,
                ArticlePipelineRerunService::STATUS_RUNNING,
            ], true)
        ) {
            $service->writeRerunMeta($article, array_merge($service->readRerunMeta($article), [
                'status' => ArticlePipelineRerunService::STATUS_FAILED,
                'failed_at' => now()->toIso8601String(),
                'message' => (string) ($payload['message'] ?? 'Rerun trước đó bị gián đoạn.'),
            ]));
            $service->abandonStaleActiveRuns((int) $article->id);
            $payload = $service->statusPayload($article->fresh() ?? $article);
        }

        $this->pipelineRerunStatus = $payload['status'];
        $this->pipelineRerunUrl = $payload['run_url'];
        $this->pipelineRerunMessage = $payload['message'];
        // Sync mode: không giữ busy từ meta — tránh nút disable + wire:poll giật UI.
        $this->pipelineRerunBusy = false;
        $this->pipelineRerunWatching = false;
    }

    public function importMarkdownFaqDebug(string $markdown): void
    {
        abort_unless(SeoAccessControl::canAccessManagerFeatures(), 403);

        $markdown = trim($markdown);
        if ($markdown === '') {
            Notification::make()
                ->title('Markdown trống')
                ->body('Dán markdown FAQ (AI output) để thử bóc tách vào panel FAQ.')
                ->warning()
                ->send();
            $this->dispatch('article-faq-markdown-import-finished', success: false);

            return;
        }

        $import = app(ArticleContentFaqService::class)->convertMarkdownImport($markdown);
        $faqs = $import['faqs'];

        if ($faqs === []) {
            $parser = app(WorkflowParserService::class);
            $standalone = $parser->shouldParseMarkdownAsStandaloneFaqSection($markdown);
            $directCount = count($parser->parseFaqsFromContent($markdown));
            $treatAllCount = count($parser->parseFaqsFromContent($markdown, true));

            Notification::make()
                ->title('Không tách được FAQ từ markdown')
                ->body(sprintf(
                    'standalone=%s, parse=%d, parse_all=%d. Kiểm tra định dạng (## FAQ hoặc ### 1. Câu hỏi? …).',
                    $standalone ? 'yes' : 'no',
                    $directCount,
                    $treatAllCount,
                ))
                ->warning()
                ->send();
            $this->dispatch('article-faq-markdown-import-finished', success: false);

            return;
        }

        $faqCount = app(ArticleFaqEditorService::class)->saveFromEditor($this->record, $faqs);
        app(ArticleFaqExtractDebugService::class)->clear($this->record);

        $baseHtml = trim($this->bootstrapEditorHtml);
        if ($baseHtml === '') {
            $baseHtml = trim((string) ($this->record->body ?? ''));
        }

        $newHtml = app(ArticleContentFaqService::class)->injectFaqPlaceholderInEditorHtml($baseHtml);
        if ($newHtml !== '') {
            $this->bootstrapEditorHtml = $newHtml;
            try {
                $written = $this->persistArticleLocalSilent($newHtml);
                if ($written === null) {
                    Notification::make()
                        ->title('Không cập nhật body FAQ')
                        ->body('Cần phiên chỉnh sửa hợp lệ để ghi body.')
                        ->warning()
                        ->send();
                }
            } catch (ArticleEditorSessionException $exception) {
                $this->notifyEditorSessionWriteBlocked($exception);
            }
        }

        $this->record->refresh();

        $this->dispatch(
            'article-faqs-extracted',
            faqs: app(ArticleFaqEditorService::class)->payloadForArticle($this->record),
            editorHtml: $newHtml,
        );

        Notification::make()
            ->title('Đã import FAQ từ markdown (debug)')
            ->body(sprintf(
                'Tách được %d FAQ vào panel. Nội dung editor giữ nguyên, chỉ chèn/cập nhật khối [omi_faq].',
                $faqCount,
            ))
            ->success()
            ->send();

        $this->dispatch('article-faq-markdown-import-finished', success: true);
    }

    /**
     * @param  array<string, mixed>|null  $debug
     */
    private function dispatchFaqExtractDebugIfPresent(?array $debug): void
    {
        if ($debug === null || $debug === []) {
            return;
        }

        $this->dispatch('article-faq-extract-debug', debug: $debug);
    }

    public function getBootstrapEditorHtml(): string
    {
        app(ArticleEditorPerfDebug::class)->recordBootstrapSize('content', $this->bootstrapEditorHtml);

        return $this->bootstrapEditorHtml;
    }

    /**
     * Phase 2 core bootstrap — the ONLY blade script tag the editor strictly needs to
     * boot: identity, content, save/conflict tokens and lazy endpoint URLs. SEO summary,
     * images, FAQs, links (with suggestions) and scoring settings all move behind the
     * `endpoints` map and are fetched by the client after mount / on panel open.
     *
     * @return array<string, mixed>
     */
    public function getEditorCoreBootstrap(): array
    {
        $metaMap = ArticleMetaMap::for($this->record);
        $conflictGuard = app(ArticleContentConflictGuard::class);
        $projectRunRevision = (string) $metaMap->get('content_project_run', '');
        $aiHistoryPending = $this->resolveAiHistoryPendingBootstrap();
        $editorDocBoot = app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class)
            ->resolveForBootstrap($this->record);
        // Prefer repaired body from bootstrap resolver when inline mark spaces were glued in DB.
        if (
            ($editorDocBoot['inline_whitespace_repaired'] ?? false) === true
            && is_string($editorDocBoot['body_html'] ?? null)
            && trim((string) $editorDocBoot['body_html']) !== ''
        ) {
            $this->bootstrapEditorHtml = app(ArticleCtaPlaceholderService::class)->highlightBlankPlaceholdersInHtml(
                (string) $editorDocBoot['body_html'],
                (int) ($this->record->site_id ?? 0) > 0 ? (int) $this->record->site_id : null,
            );
        } else {
            $boundary = app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\InlineMarkBoundaryWhitespace::class);
            $contentRepair = $boundary->repairWithReport($this->bootstrapEditorHtml);
            if ($contentRepair['repaired'] === true) {
                $this->bootstrapEditorHtml = $contentRepair['html'];
                $editorDocBoot['document'] = null;
                $editorDocBoot['source'] = 'body_html_repaired';
                $editorDocBoot['inline_whitespace_repaired'] = true;
            }
        }
        $bodyHash = $conflictGuard->contentHash($this->bootstrapEditorHtml);
        $contentRevisionSource = $projectRunRevision."\0".$bodyHash;
        $articleId = (int) $this->record->id;
        $this->record->loadMissing('site');

        $analysisPolicy = app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorAnalysisPolicyService::class)
            ->forArticle($this->record);
        $externalFacts = app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorAnalysisPolicyService::class)
            ->externalFacts($this->record);
        $contentLifecycle = app(ArticleEditorContentLifecycle::class)->bootstrapPayload(
            $this->record,
            $this->bootstrapEditorHtml,
            allowFetchFromWordPress: ! SeoAccessControl::isContentManager(),
        );

        $payload = [
            'articleId' => $articleId,
            'connectionHash' => SeoConnectionContext::hash(),
            'siteId' => (int) $this->record->site_id,
            'title' => (string) $this->articleTitle,
            'slug' => trim($this->articleSlug),
            // Light SERP fields — Google Preview paints from core, not seo-summary.
            'metaDescription' => trim($this->seoMetaDescription),
            'focusKeyword' => trim($this->focusKeyword),
            'permalinkBase' => rtrim($this->getPermalinkBase(), '/'),
            'permalinkSuffix' => $this->getPermalinkSuffix(),
            'siteDomain' => trim((string) ($this->record->site?->domain ?? '')),
            'content' => $this->bootstrapEditorHtml,
            'contentLifecycle' => $contentLifecycle,
            'status' => (string) $this->articleStatus,
            'postType' => SeoProjectTask::normalizePostType($this->articlePostType),
            'contentRevision' => hash('sha256', $contentRevisionSource),
            'updatedAt' => $this->record->updated_at?->copy()->utc()->toIso8601String(),
            'expectedUpdatedAt' => $this->record->updated_at?->copy()->utc()->toIso8601String(),
            'expectedContentHash' => $bodyHash,
            'documentVersion' => max(1, (int) ($this->record->document_version ?? 1)),
            'editorDocument' => $editorDocBoot['document'] ?? null,
            'editorDocumentSource' => $editorDocBoot['source'] ?? 'body_html',
            'editorDocumentHash' => $editorDocBoot['hash'] ?? null,
            'editorDocumentSchemaVersion' => $editorDocBoot['schema_version'] ?? null,
            'editorDocumentStatus' => $editorDocBoot['status'] ?? null,
            'inlineWhitespaceRepaired' => (bool) ($editorDocBoot['inline_whitespace_repaired'] ?? false),
            // Deprecated: exclusive lock UI ignores takeover; field retained for payload compat until product ACK.
            'canTakeoverEditorSession' => app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService::class)
                ->userCanTakeover(auth()->user() instanceof \App\Models\User ? auth()->user() : null),
            'editorSession' => [
                'leaseTtlSeconds' => (int) config('seo-content-ai.article_editor.lock_ttl_seconds', 240),
                'leaseRenewLeadSeconds' => (int) config('seo-content-ai.article_editor.lease_renew_lead_seconds', 60),
                'serverAutosaveDebounceMs' => (int) config('seo-content-ai.article_editor.server_autosave_debounce_ms', 4000),
                'endpoints' => [
                    'acquire' => route('seo.articles.edit-lease.store', ['article' => $articleId]),
                    // Deprecated: no editor UI caller; route/service retained pending product/ops approval.
                    'takeover' => route('seo.articles.editor-sessions.takeover', ['article' => $articleId]),
                ],
            ],
            'featuredImageUrl' => $this->featuredImageUrl,
            'mediaSnapshot' => app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorMediaSnapshotService::class)
                ->build($this->record, auth()->user() instanceof \App\Models\User ? auth()->user() : null, false),
            'analysisPolicy' => $analysisPolicy,
            'externalFacts' => $externalFacts,
            'supportsProductGallery' => $this->supportsProductGallery(),
            'isCanaryProduct' => in_array(strtolower(trim((string) $metaMap->get('is_canary', ''))), ['1', 'true', 'yes'], true)
                || strtolower(trim((string) $metaMap->get('canary_type', ''))) === 'product_gallery',
            'parentChildAllowed' => $this->resolveParentChildAllowedForEditor(),
            'parentChildReason' => $this->resolveParentChildReasonForEditor(),
            'productGalleryReady' => $this->supportsProductGallery()
                ? \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::isReadyOnArticle($this->record)
                : false,
            'productGallerySource' => $this->supportsProductGallery()
                ? \Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState::sourceOnArticle($this->record)
                : null,
            'authorUserId' => $this->record->user_id !== null ? (int) $this->record->user_id : null,
            'authorName' => $this->resolveArticleAuthorName(),
            'currentUserId' => auth()->id() !== null ? (int) auth()->id() : null,
            'authorIsCurrentUser' => $this->record->user_id !== null
                && auth()->id() !== null
                && (int) $this->record->user_id === (int) auth()->id(),
            'endpoints' => [
                'seoSummary' => route('seo.articles.editor.seo-summary', ['article' => $articleId]),
                'images' => route('seo.articles.editor.images', ['article' => $articleId]),
                'faqs' => route('seo.articles.editor.faqs', ['article' => $articleId]),
                'faqsCount' => route('seo.articles.editor.faqs-count', ['article' => $articleId]),
                'faqSnapshot' => route('seo.articles.editor.faq-snapshot', ['article' => $articleId]),
                'meta' => route('seo.articles.editor.meta', ['article' => $articleId]),
                'links' => route('seo.articles.editor.links', ['article' => $articleId]),
                'linksSuggestions' => route('seo.articles.editor.links-suggestions', ['article' => $articleId]),
                'vocabulary' => route('seo.articles.editor.vocabulary', ['article' => $articleId]),
                'settings' => route('seo.articles.editor.settings', ['article' => $articleId]),
                'mediaPickerConfig' => route('seo.articles.editor.media-picker-config', ['article' => $articleId]),
                'mediaSnapshot' => route('seo.articles.editor.media-snapshot', ['article' => $articleId]),
                'mediaFeatured' => route('seo.articles.editor.media.featured.set', ['article' => $articleId]),
                'mediaGallery' => route('seo.articles.editor.media.gallery.replace', ['article' => $articleId]),
            ],
            'faqCount' => (int) $this->record->faqs()->count(),
            'settings' => $this->getEditorCoreSettingsPayload($analysisPolicy, $externalFacts),
            'aiHistoryPendingApply' => $aiHistoryPending,
        ];

        app(ArticleEditorPerfDebug::class)->recordBootstrapSize('core', $payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAiHistoryPendingBootstrap(): ?array
    {
        $pending = app(ArticleAiHistoryPendingDraftStore::class)->get($this->record);
        if ($pending === null) {
            return null;
        }

        $target = trim((string) ($pending['target'] ?? ''));
        $payload = (string) ($pending['payload'] ?? '');

        // Content apply: bootstrap editor with pending HTML so draft session picks it up.
        if ($target === 'content' && trim($payload) !== '') {
            $this->bootstrapEditorHtml = app(ArticleCtaPlaceholderService::class)
                ->highlightBlankPlaceholdersInHtml(
                    $payload,
                    (int) ($this->record->site_id ?? 0) > 0 ? (int) $this->record->site_id : null,
                );
        }

        return [
            'target' => $target,
            'payload' => $payload,
            'run_id' => $pending['run_id'] ?? null,
            'attempt' => $pending['attempt'] ?? null,
            'artifact_ref' => (string) ($pending['artifact_ref'] ?? ''),
            'apply_mode' => (string) ($pending['apply_mode'] ?? 'manual_debug_apply'),
            'prompts_url' => ArticleResource::getUrl('prompts', ['record' => $this->record]),
        ];
    }

    private function resolveArticleAuthorName(): string
    {
        if ($this->record->user_id === null) {
            return __('seo-content-ai::filament.article_list.system');
        }

        $this->record->loadMissing('user');

        return trim((string) (
            $this->record->user?->display_name
            ?? $this->record->user?->email
            ?? __('seo-content-ai::filament.article_list.system')
        ));
    }

    /**
     * Minimal settings the editor needs synchronously at boot (autosave interval,
     * permission flags). Full localized scoring messages load from the settings
     * endpoint; live analysis never waits for or adopts the persisted SEO summary.
     *
     * @param  array<string, mixed>|null  $analysisPolicy
     * @param  array<string, mixed>|null  $externalFacts
     * @return array<string, mixed>
     */
    private function getEditorCoreSettingsPayload(?array $analysisPolicy = null, ?array $externalFacts = null): array
    {
        $editorSettings = app(ArticleEditorHistoryService::class)->getSettings();
        $policyService = app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorAnalysisPolicyService::class);

        return [
            'history_step' => $editorSettings['history_step'] ?? 20,
            'autosave_interval_seconds' => $editorSettings['autosave_interval_seconds'] ?? 60,
            'wiki_trust_domains' => $editorSettings['wiki_trust_domains'] ?? [],
            'show_reviews_tab' => true,
            'show_link_widgets' => true,
            'allow_wp_sync' => ! SeoAccessControl::isContentManager(),
            'can_generate_featured_snippet' => $this->canGenerateFeaturedSnippet(),
            'can_generate_outline_heading' => $this->canGenerateOutlineHeading(),
            'can_generate_image' => $this->canGenerateEditorImage(),
            'can_generate_video' => $this->canGenerateEditorVideo(),
            'can_generate_faq' => app(SeoCreateArticleSettingsService::class)->getRenewFaqPromptId() !== null,
            'prompt_hooks' => $this->getPromptHooksEditorPayload(),
            'perf_debug' => (bool) config('seo-content-ai.article_editor_perf_debug', false),
            'analysis_policy' => $analysisPolicy ?? $policyService->forArticle($this->record),
            'external_facts' => $externalFacts ?? $policyService->externalFacts($this->record),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getEditorSeoPayload(): array
    {
        $perf = app(ArticleEditorPerfDebug::class);
        $perf->start('editor_seo_bootstrap');

        $payload = array_merge(
            app(ArticleEditorSeoPayloadService::class)->forEditorBootstrap($this->record),
            [
                'article_slug' => trim($this->articleSlug),
                'permalink_suffix' => $this->getPermalinkSuffix(),
            ],
        );

        $perf->stop('editor_seo_bootstrap');
        $perf->recordBootstrapSize('seo', $payload);
        $perf->logSummary('editor_seo_bootstrap', ['article_id' => (int) $this->record->getKey()]);

        return $payload;
    }

    public function updatedSeoTitle(): void
    {
        $this->dispatchGoogleSerpPreviewUpdated();
    }

    public function updatedSeoMetaDescription(): void
    {
        $this->dispatchGoogleSerpPreviewUpdated();
    }

    public function updatedArticleTitle(): void
    {
        $this->dispatchGoogleSerpPreviewUpdated();
    }

    /**
     * @return array{google_serp_preview: array<string, mixed>}
     */
    public function updateSeoMetaDescriptionFromEditor(string $description): array
    {
        return $this->updateSeoMetaFromEditor($this->focusKeyword, $description, $this->articleSlug);
    }

    private function dispatchGoogleSerpPreviewUpdated(): void
    {
        $this->dispatch('google-serp-preview-updated', preview: $this->getGoogleSerpPreview());
    }

    /**
     * Chấm lại và lưu điểm SEO sau khi đổi focus keyword / meta SEO.
     * Dùng analyzeSubmittedContent (persist DB), không chỉ analyzePreview.
     *
     * @return array{score:int,violations?:list<string>,good?:array<int,string>,errors?:array<int,string>,warnings?:array<int,string>}
     */
    private function rescoreAndDispatchSeoAnalysis(?string $html = null): array
    {
        $this->record->unsetRelation('articleMetas');

        $html = trim((string) ($html ?? $this->bootstrapEditorHtml));
        if ($html === '') {
            $html = app(ArticleKeywordLinkReconcileService::class)->resolveArticleContent($this->record);
        }

        $slug = Str::slug($this->articleSlug);
        $seoTitle = trim($this->articleTitle);
        $seoMetaDescription = trim($this->seoMetaDescription);

        $article = $this->record->fresh(['articleMetas']) ?? $this->record;

        $result = app(SeoAnalyzerService::class)->analyzeSubmittedContent(
            $article,
            $html,
            $seoTitle !== '' ? $seoTitle : null,
            $slug !== '' ? $slug : trim((string) ($article->slug ?? '')),
            $seoMetaDescription !== '' ? $seoMetaDescription : null,
        );

        $this->record->refresh();
        // Phase 2B: React owns immediate analysis UI — do not dispatch shadow seo-analyze-result.
        // Server result remains for DB/canonical; client recomputes locally on keyword/document change.

        return $result;
    }

    /**
     * Cấu hình editor (history_step, autosave_interval_seconds trong wp_options). Undo/redo lưu localStorage phía client.
     *
     * @return array{history_step: int, autosave_interval_seconds: int}
     */
    /**
     * @return list<string>
     */
    public function getSeoAssistantPanelIds(): array
    {
        return ['seo', 'images', 'links', 'publishing', 'article'];
    }

    public function getEditorSettingsPayload(): array
    {
        $editorSettings = app(ArticleEditorHistoryService::class)->getSettings();
        $featuredSnippetThresholds = app(SeoPromptSettingsService::class)->getFeaturedSnippetThresholds();
        $promptSettings = app(SeoPromptSettingsService::class);

        $payload = [
            ...$editorSettings,
            'wiki_trust_domains' => $editorSettings['wiki_trust_domains'],
            'featured_snippet_thresholds' => $featuredSnippetThresholds,
            'article_length_product' => $promptSettings->resolveArticleLengthTarget('product'),
            'article_length_default' => $promptSettings->resolveArticleLengthTarget('article'),
            'seo_scoring_rules' => \Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry::publicRulesForClient(),
            'seo_rule_messages' => \Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry::messagesForLocale(),
            'seo_scoring_messages' => SeoEngineService::scoringMessagesForLocale(),
            'show_reviews_tab' => true,
            'can_quick_create_reviews' => ! SeoAccessControl::isContentManager() && $this->canGenerateQuickPostReviews(),
            'show_configure_reviews_link' => ! SeoAccessControl::isContentManager() && ! $this->canGenerateQuickPostReviews(),
            'quick_create_reviews_config_url' => SeoSettingsWorkflows::getUrl(),
            'show_link_widgets' => true,
            'allow_wp_sync' => ! SeoAccessControl::isContentManager(),
            'can_generate_featured_snippet' => $this->canGenerateFeaturedSnippet(),
            'can_generate_outline_heading' => $this->canGenerateOutlineHeading(),
            'can_generate_image' => $this->canGenerateEditorImage(),
            'can_generate_video' => $this->canGenerateEditorVideo(),
            'prompt_hooks' => $this->getPromptHooksEditorPayload(),
            'perf_debug' => (bool) config('seo-content-ai.article_editor_perf_debug', false),
            'analysis_policy' => app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorAnalysisPolicyService::class)
                ->forArticle($this->record),
            'external_facts' => app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorAnalysisPolicyService::class)
                ->externalFacts($this->record),
        ];

        app(ArticleEditorPerfDebug::class)->recordBootstrapSize('settings', $payload);

        return $payload;
    }

    /**
     * Flags cấu hình Prompt Hook cho Article Editor (Step 4+).
     *
     * @return array{
     *     title_suggestion: array{configured: bool, hook_key: string},
     *     meta_description_suggestion: array{configured: bool, hook_key: string},
     *     featured_snippet_generation: array{configured: bool, hook_key: string}
     * }
     */
    private function getPromptHooksEditorPayload(): array
    {
        $settings = app(SeoCreateArticleSettingsService::class);

        return [
            'title_suggestion' => [
                'configured' => $settings->getArticleTitleSuggestionPromptId() !== null,
                'hook_key' => 'article.title_suggestion',
            ],
            'meta_description_suggestion' => [
                'configured' => $settings->getArticleMetaDescriptionSuggestionPromptId() !== null,
                'hook_key' => 'article.meta_description_suggestion',
            ],
            'featured_snippet_generation' => [
                'configured' => $settings->getFeaturedSnippetPromptId() !== null,
                'hook_key' => 'article.featured_snippet.generate',
            ],
        ];
    }

    public function canGenerateEditorImage(): bool
    {
        return app(SeoCreateArticleSettingsService::class)->hasCreateImageConfiguration();
    }

    public function canGenerateEditorVideo(): bool
    {
        $settings = app(SeoCreateArticleSettingsService::class);
        $source = $settings->getCreateVideoSource();

        return $source === SeoCreateArticleSettingsService::SOURCE_WORKFLOW
            ? $settings->getCreateVideoWorkflowTaskId() !== null
            : $settings->getCreateVideoPromptId() !== null;
    }

    public function canGenerateFeaturedSnippet(): bool
    {
        return app(SeoCreateArticleSettingsService::class)->getFeaturedSnippetPromptId() !== null;
    }

    public function canGenerateOutlineHeading(): bool
    {
        return app(SeoCreateArticleSettingsService::class)->getOutlineHeadingRegeneratorPromptId() !== null;
    }

    /**
     * @return array{id: int, site_id: int, title: string, ai_debug: array<string, mixed>}
     */
    public function getEditorMetaPayload(): array
    {
        $siteId = (int) $this->record->site_id;
        $metaMap = ArticleMetaMap::for($this->record);
        $conflictGuard = app(ArticleContentConflictGuard::class);
        $projectRunRevision = (string) $metaMap->get('content_project_run', '');
        $bodyHash = $conflictGuard->contentHash($this->bootstrapEditorHtml);
        $contentRevisionSource = $projectRunRevision."\0".$bodyHash;
        $productCategoryOptions = $siteId > 0
            ? app(\Omnichannel\Addons\AiPrompt\Services\PromptLoaiSanPhamOptionsService::class)
                ->productCategoryOptionsForSite($siteId)
            : [];

        $payload = [
            'id' => (int) $this->record->id,
            'site_id' => $siteId,
            'seo_connection_hash' => SeoConnectionContext::hash(),
            'content_revision' => hash('sha256', $contentRevisionSource),
            'expected_updated_at' => $this->record->updated_at?->toIso8601String(),
            'expected_content_hash' => $bodyHash,
            'document_version' => max(1, (int) ($this->record->document_version ?? 1)),
            'media_picker_url' => route('seo.articles.media-picker', ['article' => $this->record->id]),
            'title' => (string) $this->articleTitle,
            'post_type' => SeoProjectTask::normalizePostType($this->articlePostType),
            'virtual_reviews' => [],
            'supports_product_gallery' => $this->supportsProductGallery(),
            'is_canary_product' => in_array(strtolower(trim((string) $metaMap->get('is_canary', ''))), ['1', 'true', 'yes'], true)
                || strtolower(trim((string) $metaMap->get('canary_type', ''))) === 'product_gallery',
            'parent_child_allowed' => $this->resolveParentChildAllowedForEditor(),
            'parent_child_reason' => $this->resolveParentChildReasonForEditor(),
            'product_category_options' => collect($productCategoryOptions)
                ->map(static fn (string $label, int $id): array => [
                    'id' => $id,
                    'label' => $label,
                ])
                ->values()
                ->all(),
            'product_gallery' => collect($this->productGallery)
                ->map(static fn (array $item): array => [
                    'url' => (string) ($item['url'] ?? ''),
                    'id' => max(0, (int) ($item['id'] ?? 0)),
                ])
                ->filter(static fn (array $item): bool => ($item['url'] ?? '') !== '')
                ->values()
                ->all(),
            'preview_url' => $this->getArticlePreviewUrl(),
            'can_sync_wp' => app(\Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncEligibility::class)
                ->isAllowed($this->record),
            'loai_san_pham' => $this->supportsProductGallery()
                ? trim((string) $metaMap->get('loai_san_pham', ''))
                : '',
            'gallery_description' => $this->supportsProductGallery()
                ? trim((string) $metaMap->get('gallery_description', ''))
                : '',
            'ai_debug' => $this->getEditorAiDebugPayload(),
            'supplemental_images' => $this->getEditorSupplementalImagesPayload(),
        ];

        app(ArticleEditorPerfDebug::class)->recordBootstrapSize('meta', $payload);

        return $payload;
    }

    /**
     * Minimal media picker config for initial page — no focus-keyword DB resolve.
     *
     * @return array{articleId: int, siteId: int, articleDomain: string, endpoint: string, wordPressLinked: bool, wordpress_media_available: bool, wordpress_media_unavailable_reason: string|null, defaultSearchKeyword: string, cacheScope: string}
     */
    public function getArticleMediaPickerMinimalPayload(): array
    {
        $capability = app(\Omnichannel\Addons\WordPress\Services\WordPressMediaCapabilityResolver::class)
            ->forSite($this->record->site);

        $payload = [
            'articleId' => (int) $this->record->id,
            'siteId' => (int) $this->record->site_id,
            'articleDomain' => $this->normalizeArticleMediaPickerDomain(
                (string) ($this->record->site?->domain ?? ''),
            ),
            'cacheScope' => 'u:'.(int) (auth()->id() ?? 0),
            'endpoint' => route('seo.articles.media-picker', ['article' => $this->record->id]),
            // BC: wordPressLinked = site-level WP media browse capability (not wp_post_id).
            'wordPressLinked' => $capability['available'],
            'wordpress_media_available' => $capability['available'],
            'wordpress_media_unavailable_reason' => $capability['reason'],
            'defaultSearchKeyword' => trim($this->focusKeyword),
        ];

        app(ArticleEditorPerfDebug::class)->recordBootstrapSize('media_picker_minimal', $payload);

        return $payload;
    }

    /**
     * Cấu hình JS cho modal chọn ảnh (upload thư viện nội bộ).
     *
     * @return array{articleId: int, siteId: int, articleDomain: string, endpoint: string, wordPressLinked: bool, defaultSearchKeyword: string, i18n: array<string, string>}
     */
    public function getArticleMediaPickerPayload(): array
    {
        $payload = array_merge($this->getArticleMediaPickerMinimalPayload(), [
            'i18n' => [
                'upload_success_one' => __('seo-content-ai::filament.media_tools.upload_success_one'),
                'upload_success_many' => __('seo-content-ai::filament.media_tools.upload_success_many'),
                'upload_success_body' => __('seo-content-ai::filament.media_tools.upload_success_body'),
                'upload_failed' => __('seo-content-ai::filament.media_tools.upload_failed'),
                'upload_failed_body' => __('seo-content-ai::filament.media_tools.upload_failed_body'),
            ],
        ]);

        app(ArticleEditorPerfDebug::class)->recordBootstrapSize('media_picker', $payload);

        return $payload;
    }

    /**
     * Domain WP của bài viết đang sửa (không lấy domain header / hostname Laravel).
     */
    private function normalizeArticleMediaPickerDomain(string $domain): string
    {
        $value = strtolower(trim($domain));
        if ($value === '') {
            return '';
        }

        $value = (string) preg_replace('#^https?://#i', '', $value);
        $value = (string) preg_replace('#^www\.#i', '', $value);
        $value = explode('/', $value, 2)[0] ?? '';
        $value = explode('?', $value, 2)[0] ?? '';
        $value = explode('#', $value, 2)[0] ?? '';
        $value = rtrim(trim($value), '.');

        return $value;
    }

    /**
     * Ảnh ngoài block editor (ảnh đại diện + album sản phẩm) để hiển thị trong tab Hình ảnh.
     *
     * @return list<array<string, mixed>>
     */
    private function getEditorSupplementalImagesPayload(): array
    {
        return app(\Omnichannel\Addons\Content\Services\ArticleEditorSupplementalImagesService::class)
            ->forArticle($this->record, (string) ($this->featuredImageUrl ?? ''), $this->productGallery);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEditorAiDebugPayload(): array
    {
        if (! config('app.debug')) {
            return ['enabled' => false];
        }

        $settings = app(SeoCreateArticleSettingsService::class);
        $imagePromptId = $this->resolveEditorImageDebugPromptId($settings);

        return [
            'enabled' => true,
            'article_title' => trim((string) ($this->record->title ?? '')),
            'focus_keyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($this->record) ?? '',
            'image' => $this->buildPromptDebugPayload($imagePromptId),
            'video' => $this->buildPromptDebugPayload($settings->getCreateVideoPromptId()),
        ];
    }

    private function resolveEditorImageDebugPromptId(SeoCreateArticleSettingsService $settings): ?int
    {
        if ($settings->getCreateTypographyImageSource() === SeoCreateArticleSettingsService::SOURCE_WORKFLOW) {
            $taskId = $settings->getCreateTypographyImageTaskId();
            if ($taskId === null) {
                return null;
            }

            try {
                return (int) app(EditorImageTaskResolverService::class)
                    ->resolveImagePrompt($taskId)
                    ->id;
            } catch (\Throwable) {
                return null;
            }
        }

        return $settings->getCreateTypographyImagePromptId();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPromptDebugPayload(?int $promptId): array
    {
        if ($promptId === null) {
            return [
                'prompt_id' => null,
                'name' => '',
                'template' => '',
                'variables' => [],
                'tools' => '',
                'connection_name' => '',
                'execution' => '',
            ];
        }

        $prompt = SeoPrompt::query()->with('aiConnection')->find($promptId);
        if (! $prompt instanceof SeoPrompt) {
            return [
                'prompt_id' => $promptId,
                'name' => '',
                'template' => '',
                'variables' => [],
                'tools' => '',
                'connection_name' => '',
                'execution' => '',
            ];
        }

        $variableNames = collect(is_array($prompt->variables) ? $prompt->variables : [])
            ->map(static fn (array $row): string => trim((string) ($row['name'] ?? '')))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values()
            ->all();

        $placeholderVars = [];
        foreach ($variableNames as $name) {
            $placeholderVars[$name] = '{{'.$name.'}}';
        }

        try {
            $template = app(PromptRunnerService::class)->compilePrompt($prompt, $placeholderVars);
        } catch (\Throwable) {
            $template = (string) ($prompt->markdown_content ?? '');
        }

        $tools = strtolower(trim((string) ($prompt->tools ?? 'default')));
        $connectionName = trim((string) ($prompt->aiConnection?->name ?? ''));
        $execution = match ($tools) {
            'image' => 'Imagen 4 hoặc Nano Banana (Gemini Image API)',
            'video' => 'Veo / video pipeline',
            default => 'Văn bản (Gemini Flash / Claude)',
        };

        return [
            'prompt_id' => (int) $prompt->id,
            'name' => (string) ($prompt->name ?? ''),
            'template' => $template,
            'variables' => $variableNames,
            'tools' => $tools,
            'connection_name' => $connectionName,
            'execution' => $execution,
        ];
    }

    /**
     * Danh sách ảnh trong bài (meta wp_post_images, đồng bộ từ WordPress).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEditorImagesPayload(): array
    {
        $payload = app(ArticlePostImagesService::class)->resolveForArticle($this->record);
        app(ArticleEditorPerfDebug::class)->recordBootstrapSize('images', $payload);

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEditorFaqsPayload(): array
    {
        $payload = app(ArticleFaqEditorService::class)->payloadForArticle($this->record);
        app(ArticleEditorPerfDebug::class)->recordBootstrapSize('faqs', $payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFaqExtractDebugPayload(): ?array
    {
        return app(ArticleFaqExtractDebugService::class)->get($this->record);
    }

    public function clearFaqExtractDebug(): void
    {
        app(ArticleFaqExtractDebugService::class)->dismiss($this->record);
        $this->dispatch('article-faq-extract-debug-cleared');
    }

    /**
     * @param  list<array<string, mixed>>  $faqs
     */
    public function extractFaqsFromSelection(string $html, string $articleHtml = ''): void
    {
        try {
            $user = auth()->user();
            $result = app(ArticleFaqManualExtractService::class)
                ->extractFromHtmlFragment(
                    $this->record,
                    $html,
                    $articleHtml,
                    $user instanceof \App\Models\User ? $user : null,
                    $this->editorSessionId,
                    $this->expectedDocumentVersion,
                );
        } catch (ArticleEditorSessionException $exception) {
            $this->notifyEditorSessionWriteBlocked($exception);

            return;
        } catch (FaqManualExtractException $exception) {
            $this->dispatch('article-faq-extract-debug', debug: $exception->debug);

            Notification::make()
                ->title('Unable to extract FAQ')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Unable to extract FAQ')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        $faqs = $result['faqs'] ?? [];
        $editorHtml = (string) ($result['editor_html'] ?? '');

        $this->record->refresh();
        $this->expectedDocumentVersion = max(
            1,
            (int) (($this->record->document_version) ?? $this->expectedDocumentVersion ?? 1),
        );

        $this->dispatch('article-faqs-extracted', faqs: $faqs, editorHtml: $editorHtml);

        Notification::make()
            ->title('FAQ extracted and saved')
            ->body('FAQ items: '.count($faqs).'. FAQ content in editor has been replaced with [omi_faq].')
            ->success()
            ->send();
    }

    public function saveArticleFaqs(array $faqs): void
    {
        $this->saveArticleFaqsWithOptionalCollect($faqs, allowCollectContinuation: true);
    }

    /**
     * @param  list<array<string, mixed>>  $faqs
     */
    private function saveArticleFaqsInline(array $faqs): void
    {
        $this->saveArticleFaqsWithOptionalCollect($faqs, allowCollectContinuation: false);
    }

    /**
     * @param  list<array<string, mixed>>  $faqs
     */
    private function saveArticleFaqsWithOptionalCollect(array $faqs, bool $allowCollectContinuation): void
    {
        $previousCount = $this->record->faqs()->count();
        $incomingCount = count(array_filter($faqs, static function ($row): bool {
            if (! is_array($row)) {
                return false;
            }

            return trim((string) ($row['question'] ?? '')) !== '';
        }));

        $savedCount = app(ArticleFaqEditorService::class)->saveFromEditor($this->record, $faqs);

        if ($savedCount > 0) {
            $this->dispatch('article-faqs-save-finished');
        }

        if ($savedCount === 0 && $incomingCount > 0) {
            if ($allowCollectContinuation && $this->pendingEditorCollectTarget !== null) {
                $target = $this->pendingEditorCollectTarget;
                $this->pendingEditorCollectTarget = null;
                $this->dispatch('collect-editor-html', target: $target);

                return;
            }

            if (! $allowCollectContinuation) {
                return;
            }

            Notification::make()
                ->title('FAQ not saved')
                ->body('Each FAQ needs a question and a non-empty answer.')
                ->warning()
                ->send();

            return;
        }

        if ($savedCount === 0) {
            $restore = app(ArticleFaqWordPressRestoreService::class)->restoreWhenFaqsCleared($this->record);

            if ($restore['restored'] && filled($restore['editor_html'] ?? null)) {
                $this->bootstrapEditorHtml = (string) $restore['editor_html'];
                $this->record->refresh();

                $this->dispatch(
                    'article-faqs-extracted',
                    faqs: [],
                    editorHtml: $this->bootstrapEditorHtml,
                );

                if ($allowCollectContinuation && $this->pendingEditorCollectTarget !== null) {
                    $target = $this->pendingEditorCollectTarget;
                    $this->pendingEditorCollectTarget = null;
                    $this->dispatch('collect-editor-html', target: $target);

                    return;
                }

                if (! $allowCollectContinuation) {
                    return;
                }

                Notification::make()
                    ->title('FAQ deleted')
                    ->body((string) ($restore['message'] ?? 'Article content has been restored from WordPress.'))
                    ->success()
                    ->send();

                return;
            }

            if ($allowCollectContinuation && $this->pendingEditorCollectTarget !== null) {
                $target = $this->pendingEditorCollectTarget;
                $this->pendingEditorCollectTarget = null;
                $this->dispatch('collect-editor-html', target: $target);

                return;
            }

            if (! $allowCollectContinuation) {
                return;
            }

            Notification::make()
                ->title('FAQ deleted')
                ->body((string) ($restore['message'] ?? 'FAQ has been removed from SEO system.'))
                ->warning()
                ->send();

            return;
        }

        if ($savedCount < $previousCount) {
            $restore = app(ArticleFaqWordPressRestoreService::class)->restoreAfterFaqRemoved($this->record, $faqs);

            if ($restore['restored'] && filled($restore['editor_html'] ?? null)) {
                $this->bootstrapEditorHtml = (string) $restore['editor_html'];
                $this->record->refresh();

                $this->dispatch(
                    'article-faqs-extracted',
                    faqs: app(ArticleFaqEditorService::class)->payloadForArticle($this->record),
                    editorHtml: $this->bootstrapEditorHtml,
                );

                if ($allowCollectContinuation && $this->pendingEditorCollectTarget !== null) {
                    $target = $this->pendingEditorCollectTarget;
                    $this->pendingEditorCollectTarget = null;
                    $this->dispatch('collect-editor-html', target: $target);

                    return;
                }

                if (! $allowCollectContinuation) {
                    return;
                }

                Notification::make()
                    ->title('FAQ deleted')
                    ->body((string) ($restore['message'] ?? 'Article content has been restored from WordPress.'))
                    ->success()
                    ->send();

                return;
            }
        }

        if ($allowCollectContinuation && $this->pendingEditorCollectTarget !== null) {
            $target = $this->pendingEditorCollectTarget;
            $this->pendingEditorCollectTarget = null;
            $this->dispatch('collect-editor-html', target: $target);

            return;
        }

        if (! $allowCollectContinuation) {
            return;
        }

        Notification::make()
            ->title('FAQ saved')
            ->body('FAQ is saved in SEO system. Sync to WordPress when clicking "Sync".')
            ->success()
            ->send();

    }

    /**
     * @return array{
     *     rendered: string,
     *     prompt_id: int,
     *     prompt_name: string,
     *     post_processing: array{
     *         split_enabled: bool,
     *         split_grid_size: int,
     *         split_rows: int,
     *         split_columns: int,
     *         expected_panels: int,
     *         resize_enabled: bool,
     *         resize_width: int|null,
     *         resize_height: int|null,
     *     },
     *     error?: string,
     * }
     */
    public function previewGenerateArticleImagePrompt(
        string $userBrief,
        string $target = 'editor',
        int $loaiSanPhamCategoryArticleId = 0,
        string $loaiSanPhamCustom = '',
        string $selectionText = '',
    ): array {
        return app(ArticleEditorMediaAiService::class)->previewRenderedImagePrompt(
            $this->record,
            $userBrief,
            $target,
            $loaiSanPhamCategoryArticleId,
            $loaiSanPhamCustom,
            $selectionText,
        );
    }

    /**
     * @return array{
     *     rendered: string,
     *     prompt_id: int,
     *     prompt_name: string,
     *     post_processing?: array<string, mixed>,
     *     error?: string,
     * }
     */
    public function previewGenerateArticleVideoPrompt(
        string $userBrief,
        string $target = 'editor',
        int $loaiSanPhamCategoryArticleId = 0,
        string $loaiSanPhamCustom = '',
        string $selectionText = '',
    ): array {
        return app(ArticleEditorMediaAiService::class)->previewRenderedVideoPrompt(
            $this->record,
            $userBrief,
            $selectionText,
        );
    }

    /**
     * @return array{ok: bool, message?: string, url?: string, seo_media_id?: int, status?: string}
     */
    public function generateArticleImageFromEditor(
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        string $activeBlockId = '',
        string $target = 'editor',
        int $loaiSanPhamCategoryArticleId = 0,
        string $loaiSanPhamCustom = '',
        string $galleryGenerationMode = 'sprite',
    ): array {
        try {
            $result = app(ArticleEditorMediaAiService::class)->generateImage(
                $this->record,
                $selectionText,
                $selectionHtml,
                $userBrief,
                $activeBlockId,
                $target,
                $loaiSanPhamCategoryArticleId,
                $loaiSanPhamCustom,
                $galleryGenerationMode,
            );
        } catch (\Throwable $exception) {
            $presented = $exception instanceof PromptRunException
                ? [
                    'user_message' => $exception->userMessage(),
                    'technical_details' => $exception->technicalDetails(),
                    'classification' => $exception->classification(),
                    'retryable' => $exception->isRetryable(),
                ]
                : \Omnichannel\Addons\Media\Support\ImagenProviderErrorClassifier::present($exception->getMessage());

            $message = (string) $presented['user_message'];
            $technical = (string) $presented['technical_details'];

            $this->dispatch(
                'article-ai-media-failed',
                type: 'image',
                message: $message,
                technicalDetails: $technical,
                classification: $presented['classification'] ?? null,
                retryable: (bool) ($presented['retryable'] ?? false),
            );

            Notification::make()
                ->title(__('seo-content-ai::common.generate_image_failed'))
                ->body($message)
                ->danger()
                ->send();

            return [
                'ok' => false,
                'message' => $message,
                'technical_details' => $technical,
                'classification' => $presented['classification'] ?? null,
                'retryable' => (bool) ($presented['retryable'] ?? false),
            ];
        }

        $result = app(ArticleEditorMediaAiService::class)->enforceGenerateImageSettlement($result);
        $seoMediaId = (int) ($result['seo_media_id'] ?? 0);
        $galleryExecutionId = trim((string) ($result['gallery_execution_id'] ?? ''));
        if ((string) ($result['status'] ?? '') === 'failed' && $seoMediaId <= 0 && $galleryExecutionId === '') {
            $message = (string) ($result['message'] ?? $result['error_message'] ?? __('seo-content-ai::common.generate_image_failed'));
            $this->dispatch(
                'article-ai-media-failed',
                type: 'image',
                message: $message,
            );

            Notification::make()
                ->title(__('seo-content-ai::common.generate_image_failed'))
                ->body($message)
                ->danger()
                ->send();

            return [
                'ok' => false,
                'message' => $message,
                'status' => 'failed',
                'seo_media_id' => 0,
            ];
        }

        $galleryUrls = $seoMediaId > 0
            ? $this->resolvePostProcessingGalleryUrlsByMediaId($seoMediaId)
            : [];

        $this->dispatch(
            'article-ai-image-generated',
            url: $result['url'] ?? '',
            activeBlockId: $activeBlockId,
            seoMediaId: $seoMediaId,
            status: (string) ($result['status'] ?? 'processing'),
            mediaType: 'image',
            target: $target,
            gallery_urls: $galleryUrls,
            galleryUrls: $galleryUrls,
            gallery_execution_id: $galleryExecutionId,
            galleryExecutionId: $galleryExecutionId,
            supports_reference_image: (bool) ($result['supports_reference_image'] ?? false),
            resolved_model: (string) ($result['resolved_model'] ?? ''),
        );

        if ((string) ($result['status'] ?? 'processing') === 'processing') {
            Notification::make()
                ->title(__('seo-content-ai::common.generating_image'))
                ->body(__('seo-content-ai::common.placeholder_inserted'))
                ->success()
                ->send();
        }

        return [
            'ok' => true,
            'url' => (string) ($result['url'] ?? ''),
            'seo_media_id' => $seoMediaId,
            'status' => (string) ($result['status'] ?? 'processing'),
            'gallery_execution_id' => $galleryExecutionId,
            'supports_reference_image' => (bool) ($result['supports_reference_image'] ?? false),
            'resolved_model' => (string) ($result['resolved_model'] ?? ''),
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     supports_reference_image: bool,
     *     model: string|null,
     *     eligible: list<string>
     * }
     */
    public function resolveProductGalleryReferenceCapability(): array
    {
        $capability = app(ArticleEditorMediaAiService::class)->resolveProductGalleryReferenceCapability();

        return [
            'ok' => true,
            'supports_reference_image' => (bool) ($capability['supports_reference_image'] ?? false),
            'model' => $capability['model'] ?? null,
            'eligible' => is_array($capability['eligible'] ?? null) ? $capability['eligible'] : [],
        ];
    }

    /**
     * @return array{ok: bool, status: string, gallery_execution_id: string, message?: string, failure_reason?: string}
     */
    public function pollProductGalleryExecutionStatus(string $executionId): array
    {
        $executionId = trim($executionId);
        if ($executionId === '') {
            return [
                'ok' => false,
                'status' => 'failed',
                'gallery_execution_id' => '',
                'message' => 'Thiếu gallery_execution_id.',
            ];
        }

        $row = \Omnichannel\Addons\Commerce\Models\SeoProductGalleryExecution::query()
            ->where('execution_id', $executionId)
            ->where('article_id', (int) $this->record->id)
            ->first();

        if (! $row instanceof \Omnichannel\Addons\Commerce\Models\SeoProductGalleryExecution) {
            return [
                'ok' => false,
                'status' => 'failed',
                'gallery_execution_id' => $executionId,
                'message' => 'Không tìm thấy gallery execution.',
            ];
        }

        $raw = strtolower(trim((string) ($row->status ?? '')));
        $status = match (true) {
            in_array($raw, ['completed', 'completed_fallback'], true) => 'completed',
            in_array($raw, ['failed', 'cancelled'], true) => 'failed',
            default => 'processing',
        };

        return [
            'ok' => true,
            'status' => $status,
            'gallery_execution_id' => $executionId,
            'raw_status' => $raw,
            'failure_reason' => (string) ($row->failure_reason ?? ''),
            'message' => $status === 'failed'
                ? ((string) ($row->failure_reason ?? '') !== ''
                    ? (string) $row->failure_reason
                    : 'Parent/Child gallery thất bại.')
                : '',
        ];
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    private function resolvePostProcessingGalleryUrlsByMediaId(int $seoMediaId): array
    {
        $media = SeoMedia::query()->find($seoMediaId);
        if (! $media instanceof SeoMedia) {
            return [];
        }

        $source = app(PromptPostProcessingApplyService::class)->resolveSourceMedia($media);
        $variables = is_array($source->prompt_variables) ? $source->prompt_variables : [];
        $pieceIds = is_array($variables['post_processing_piece_ids'] ?? null)
            ? $variables['post_processing_piece_ids']
            : [];

        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $pieceIds,
        ), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        return SeoMedia::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(static fn (SeoMedia $piece): array => [
                'id' => (int) $piece->id,
                'url' => $piece->publicUrl(),
            ])
            ->values()
            ->all();
    }

    public function generateArticleVideoFromEditor(
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        string $activeBlockId = '',
    ): void {
        try {
            $result = app(ArticleEditorMediaAiService::class)->generateVideo(
                $this->record,
                $selectionText,
                $selectionHtml,
                $userBrief,
                $activeBlockId,
            );
        } catch (\Throwable $exception) {
            $this->dispatch('article-ai-media-failed', type: 'video', message: $exception->getMessage());

            Notification::make()
                ->title(__('seo-content-ai::common.generate_video_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->dispatch(
            'article-ai-video-generated',
            url: $result['url'],
            activeBlockId: $activeBlockId,
            seoMediaId: (int) ($result['seo_media_id'] ?? 0),
            status: (string) ($result['status'] ?? 'processing'),
            mediaType: 'video',
        );

        Notification::make()
            ->title(__('seo-content-ai::common.generating_video'))
            ->body(__('seo-content-ai::common.placeholder_inserted'))
            ->success()
            ->send();
    }

    public function renewArticleFaq(int $index, string $question, string $answer): void
    {
        try {
            $renewed = app(ArticleFaqEditorService::class)->renewFaq(
                $this->record,
                $question,
                $answer,
            );
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title('Unable to refresh FAQ')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->dispatch(
            'article-faq-renewed',
            index: $index,
            question: $renewed['question'],
            answer: $renewed['answer'],
        );
    }

    public function canGenerateArticleFaqs(): bool
    {
        return app(SeoCreateArticleSettingsService::class)->getRenewFaqPromptId() !== null;
    }

    public function requestGenerateArticleFaqs(): void
    {
        if (! $this->canGenerateArticleFaqs()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.faq_generate_failed'))
                ->body(__('seo-content-ai::filament.article_edit.faq_generate_no_prompt'))
                ->warning()
                ->send();

            return;
        }

        // Tránh double-click / echo flush → 2 lần collect trước khi generate chạy.
        if ($this->pendingEditorCollectTarget === 'generate-faq') {
            return;
        }

        if (Cache::has($this->articleFaqGenerateLockKey().':held')) {
            return;
        }

        $this->pendingEditorCollectTarget = 'generate-faq';
        $this->dispatch('flush-article-faqs');
        $this->dispatch('article-faq-generate-started');
    }

    public function generateArticleFaqs(string $editorHtml = ''): void
    {
        $lockKey = $this->articleFaqGenerateLockKey();
        $lock = Cache::lock($lockKey, 180);
        if (! $lock->get()) {
            return;
        }

        Cache::put($lockKey.':held', 1, 180);

        try {
            $user = auth()->user();
            $result = app(ArticleFaqGeneratorService::class)->generate(
                $this->record,
                $editorHtml,
                $user instanceof \App\Models\User ? $user : null,
                $this->editorSessionId,
                $this->expectedDocumentVersion,
            );

            $html = (string) ($result['editor_html'] ?? '');
            if ($html !== '') {
                $this->bootstrapEditorHtml = $html;
            }

            $this->record->refresh();
            $this->expectedDocumentVersion = max(
                1,
                (int) (($this->record->document_version) ?? $this->expectedDocumentVersion ?? 1),
            );

            $this->dispatch(
                'article-faqs-extracted',
                faqs: $result['faqs'] ?? [],
                editorHtml: $html,
            );

            $count = (int) ($result['faq_count'] ?? 0);

            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.faq_generate_success'))
                ->body(__('seo-content-ai::filament.article_edit.faq_generate_success_body', ['count' => $count]))
                ->success()
                ->send();
        } catch (ArticleEditorSessionException $exception) {
            $this->notifyEditorSessionWriteBlocked($exception);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.faq_generate_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            Cache::forget($lockKey.':held');
            $lock->release();
            $this->dispatch('article-faq-generate-finished');
        }
    }

    private function articleFaqGenerateLockKey(): string
    {
        return 'seo-article-faq-generate-'.(int) $this->record->id;
    }

    /**
     * @return list<array{id: int, title: string, url: string, label: string}>
     */
    public function searchInternalLinkArticles(string $query = ''): array
    {
        return app(ArticleInternalLinkSearchService::class)->search(
            (int) $this->record->site_id,
            (int) $this->record->id,
            $query,
        );
    }

    /**
     * Vocabulary Plan: assign phrases directly to a Content Project (no Assign drawer).
     *
     * Soft-full monthly capacity does not block; archived projects remain blocked.
     *
     * @param  list<string|array{keyword?: string, title?: string}>  $items
     * @return array{
     *     success: bool,
     *     message: string,
     *     summary: array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int, existing_article:int}
     * }
     */
    public function assignVocabularyItemsToContentProject(int $projectId, array $items = []): array
    {
        $phrases = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $phrases[] = $item;
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $phrase = trim((string) ($item['keyword'] ?? $item['title'] ?? $item['phrase'] ?? ''));
            if ($phrase !== '') {
                $phrases[] = $phrase;
            }
        }

        $siteId = (int) (ArticleResource::resolveArticleSiteId($this->record) ?? $this->record->site_id ?? 0);
        if ($projectId <= 0 || $siteId <= 0 || $phrases === []) {
            return [
                'success' => false,
                'message' => __('seo-content-ai::filament.articles_optimal.assign_failed'),
                'summary' => [
                    'added' => 0,
                    'duplicate' => 0,
                    'overflow' => 0,
                    'domain_mismatch' => 0,
                    'already_in_project' => 0,
                    'existing_article' => 0,
                ],
            ];
        }

        $summary = app(KeywordProjectAssignmentService::class)->assignPhrases(
            $phrases,
            $projectId,
            $siteId,
            false,
            true,
        );

        $added = (int) ($summary['added'] ?? 0);
        $body = ArticleResource::buildAssignContentProjectBody($summary);

        if ($added > 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.assign_completed'))
                ->body($body)
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->body($body !== '' ? $body : __('seo-content-ai::filament.articles_optimal.assign_failed'))
                ->warning()
                ->send();
        }

        return [
            'success' => $added > 0,
            'message' => $body,
            'summary' => $summary,
        ];
    }

    public function generateFeaturedSnippetFromEditor(string $refBlockId, string $position = 'after'): void
    {
        if (! $this->canGenerateFeaturedSnippet()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.featured_snippet_generate_failed'))
                ->body(__('seo-content-ai::filament.article_edit.featured_snippet_generate_no_prompt'))
                ->warning()
                ->send();

            return;
        }

        try {
            $html = app(ArticleFeaturedSnippetGeneratorService::class)->generate($this->record);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.featured_snippet_generate_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->dispatch(
            'article-featured-snippet-generated',
            html: $html,
            blockId: trim($refBlockId),
            position: in_array($position, ['before', 'after'], true) ? $position : 'after',
        );

        Notification::make()
            ->title(__('seo-content-ai::filament.article_edit.featured_snippet_generate_success'))
            ->success()
            ->send();
    }

    /**
     * @return array{duplicate: bool, duplicate_scope: ?string}
     */
    public function checkFaqQuestionDuplicate(string $question, ?int $faqId = null): array
    {
        return app(ArticleFaqEditorService::class)->checkDuplicate(
            $this->record,
            $question,
            $faqId !== null && $faqId > 0 ? $faqId : null,
        );
    }

    public function getEditorOutlineMarkdown(): string
    {
        $this->record->loadMissing('articleMetas');

        /** @var ArticleMeta|null $meta */
        $meta = $this->record->articleMetas->firstWhere('meta_key', 'seo_article_outline');
        if ($meta !== null && is_string($meta->meta_value) && trim($meta->meta_value) !== '') {
            return $meta->meta_value;
        }

        $blocks = $this->record->blocks;
        if (is_array($blocks)) {
            if (is_string($blocks['outline'] ?? null) && trim($blocks['outline']) !== '') {
                return trim($blocks['outline']);
            }
            if (is_string($blocks['markdown'] ?? null) && trim($blocks['markdown']) !== '') {
                return trim($blocks['markdown']);
            }
        }

        return '';
    }

    /**
     * @return array{success: bool, message: string, outline: string}
     */
    public function rewriteOutlineFromWorkflow(string $mode = 'title', string $title = '', string $html = ''): array
    {
        $mode = $mode === 'content' ? 'content' : 'title';

        $taskId = app(SeoCreateArticleSettingsService::class)->getPublishArticleTaskId();
        if ($taskId === null) {
            return [
                'success' => false,
                'message' => 'Chưa cấu hình workflow Sửa bài viết / Đăng bài viết trong SEO -> Settings -> Workflows.',
                'outline' => '',
            ];
        }

        $task = SeoTask::query()->find($taskId);
        if (! $task instanceof SeoTask) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy workflow #'.$taskId.'.',
                'outline' => '',
            ];
        }

        if (! $task->is_active) {
            return [
                'success' => false,
                'message' => 'Workflow "'.$task->name.'" đang tắt.',
                'outline' => '',
            ];
        }

        $currentTitle = trim($title !== '' ? $title : (string) ($this->record->title ?? ''));
        if ($currentTitle === '') {
            $currentTitle = 'Article #'.(int) $this->record->id;
        }

        $input = $mode === 'content'
            ? trim(strip_tags($html !== '' ? $html : (string) ($this->record->body ?? '')))
            : $currentTitle;
        if ($input === '') {
            $input = $currentTitle;
        }

        $focusKeyword = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($this->record) ?? $currentTitle;
        $scope = function (\Illuminate\Database\Eloquent\Builder $query): void {
            SeoAccessControl::applyAccessibleSiteScope($query);
        };

        try {
            $context = app(TaskTestInputResolver::class)->resolve(
                (int) $this->record->id,
                $currentTitle,
                $focusKeyword,
                $scope,
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'outline' => '',
            ];
        }

        $variables = $context->variables;
        $variables['input'] = $input;
        if ($mode === 'content') {
            $variables['post_content'] = $input;
        }

        $workflowContext = new TaskTestContext(
            article: $context->article,
            isNewArticle: false,
            matchedBy: $context->matchedBy,
            variables: $variables,
            summary: $context->summary,
        );

        try {
            $steps = app(TaskWorkflowTestRunner::class)->run($task, $workflowContext);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'outline' => '',
            ];
        }

        $hasFailedStep = collect($steps)->contains(fn (array $step): bool => (string) ($step['status'] ?? '') === 'failed');
        if ($hasFailedStep) {
            $failedMessage = collect($steps)
                ->filter(fn (array $step): bool => (string) ($step['status'] ?? '') === 'failed')
                ->map(fn (array $step): string => trim((string) ($step['message'] ?? 'Workflow step failed.')))
                ->filter(fn (string $message): bool => $message !== '')
                ->first();

            return [
                'success' => false,
                'message' => $failedMessage !== null ? $failedMessage : 'Workflow chạy lỗi.',
                'outline' => '',
            ];
        }

        app(TaskWorkflowTestRunner::class)->applyParsedMetaFromSteps($this->record, $steps);
        $this->record->refresh();

        $outline = trim($this->getEditorOutlineMarkdown());
        if ($outline === '') {
            $outline = trim((string) collect($steps)
                ->reverse()
                ->map(fn (array $step): string => trim((string) ($step['output'] ?? '')))
                ->first(fn (string $output): bool => $output !== ''));
        }

        if ($outline === '') {
            return [
                'success' => false,
                'message' => 'Workflow đã chạy nhưng chưa tạo được outline.',
                'outline' => '',
            ];
        }

        $this->record->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_article_outline'],
            ['meta_value' => $outline],
        );
        $this->record->refresh();

        return [
            'success' => true,
            'message' => 'Đã tạo outline từ workflow.',
            'outline' => $outline,
        ];
    }

    /**
     * Đổi tên file attachment trên WordPress + thay URL cũ trong mọi bài viết.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function renameAttachmentSlugsOnWordPress(array $items, bool $silent = false): void
    {
        // Bulk WP rename forbidden — Fix Slug All must not rename WordPress media.
        $result = app(\Omnichannel\Addons\WordPress\Services\WordPressMediaRenameService::class)
            ->rejectBulkWordPressRename($items);

        $renamed = is_array($result['renamed'] ?? null) ? $result['renamed'] : [];
        $renamed = $this->enrichAttachmentRenameResultsWithRequestMeta($items, $renamed);

        // WP đã đổi file + post_content; Laravel body/featured/gallery vẫn URL cũ → rewrite trước reload.
        if ($renamed !== []) {
            $this->rewriteArticleUrlsAfterWpAttachmentRename($items, $renamed);
            $this->record->refresh();
            $this->bootstrapEditorHtml = app(WordPressArticleContentService::class)->resolveEditorHtml($this->record);
        }

        if ($result['success']) {
            $this->dispatch('seo-attachment-slugs-rename-finished', success: true, renamed: $renamed, message: $result['message']);

            if (! $silent) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.article_edit.image_slug_renamed_wp'))
                    ->body($result['message'])
                    ->success()
                    ->send();
            }

            return;
        }

        $this->dispatch('seo-attachment-slugs-rename-finished', success: false, renamed: $renamed, message: $result['message']);

        if (! $silent) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.image_slug_rename_wp_failed'))
                ->body($result['message'])
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $requestItems
     * @param  array<int, array<string, mixed>>  $renamed
     */
    private function rewriteArticleUrlsAfterWpAttachmentRename(array $requestItems, array $renamed): void
    {
        $urlMap = [];

        foreach ($renamed as $row) {
            if (! is_array($row)) {
                continue;
            }

            $oldUrl = trim((string) ($row['old_url'] ?? ''));
            $newUrl = trim((string) ($row['new_url'] ?? ''));
            $newSlug = trim((string) ($row['new_slug'] ?? ''));

            if ($oldUrl === '') {
                continue;
            }

            if ($newUrl === '' && $newSlug !== '') {
                $newUrl = $this->replaceAttachmentUrlSlug($oldUrl, $newSlug);
            }

            if ($newUrl === '' || $oldUrl === $newUrl) {
                continue;
            }

            $urlMap[$oldUrl] = $newUrl;
        }

        // Recovery: WP báo renamed thiếu new_url — dựng map từ queue request.
        foreach ($requestItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $oldUrl = trim((string) ($item['old_url'] ?? $item['oldUrl'] ?? $item['src'] ?? ''));
            $newSlug = trim((string) ($item['new_slug'] ?? $item['slug'] ?? ''));
            if ($oldUrl === '' || $newSlug === '') {
                continue;
            }

            $computed = $this->replaceAttachmentUrlSlug($oldUrl, $newSlug);
            if ($computed === '' || $computed === $oldUrl || isset($urlMap[$oldUrl])) {
                continue;
            }

            $urlMap[$oldUrl] = $computed;
        }

        if ($urlMap === []) {
            return;
        }

        try {
            app(SeoMediaUrlReplacementService::class)->rewriteArticleReferences($this->record, $urlMap);
        } catch (ArticleEditorSessionException $exception) {
            Notification::make()
                ->title('Không rewrite URL media')
                ->body($exception->getMessage())
                ->warning()
                ->send();
        }
    }

    private function replaceAttachmentUrlSlug(string $url, string $newSlug): string
    {
        $url = trim($url);
        $newSlug = trim($newSlug);
        if ($url === '' || $newSlug === '') {
            return $url;
        }

        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if ($path === '') {
            return $url;
        }

        $dirname = pathinfo($path, PATHINFO_DIRNAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = $extension !== '' ? $newSlug.'.'.$extension : $newSlug;
        $nextPath = ($dirname === '/' || $dirname === '\\' || $dirname === '.' ? '' : rtrim($dirname, '/'))
            .'/'.$filename;

        $rebuilt = '';
        if (isset($parts['scheme'], $parts['host'])) {
            $rebuilt = $parts['scheme'].'://'.$parts['host'];
            if (isset($parts['port'])) {
                $rebuilt .= ':'.$parts['port'];
            }
        }
        $rebuilt .= $nextPath;
        if (! empty($parts['query'])) {
            $rebuilt .= '?'.$parts['query'];
        }
        if (! empty($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt !== '' ? $rebuilt : $url;
    }

    /**
     * Gắn block_id / old_url từ request vào kết quả rename WordPress (plugin không echo block_id).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $renamed
     * @return array<int, array<string, mixed>>
     */
    private function enrichAttachmentRenameResultsWithRequestMeta(array $items, array $renamed): array
    {
        $byAttachmentId = [];
        $byOldUrl = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $attachmentId = (int) ($item['attachment_id'] ?? $item['wp_attachment_id'] ?? 0);
            $blockId = trim((string) ($item['block_id'] ?? $item['blockId'] ?? ''));
            $oldUrl = trim((string) ($item['old_url'] ?? $item['oldUrl'] ?? $item['src'] ?? ''));
            $oldUrlKey = $oldUrl !== '' ? $this->normalizeAttachmentRenameUrlKey($oldUrl) : '';

            $meta = [
                'block_id' => $blockId,
                'old_url' => $oldUrl,
            ];

            if ($attachmentId > 0) {
                $byAttachmentId[$attachmentId] = $meta;
            }
            if ($oldUrlKey !== '') {
                $byOldUrl[$oldUrlKey] = $meta;
            }
        }

        $enriched = [];
        foreach ($renamed as $row) {
            if (! is_array($row)) {
                continue;
            }

            $attachmentId = (int) ($row['attachment_id'] ?? 0);
            $responseOldUrl = trim((string) ($row['old_url'] ?? ''));
            $responseOldUrlKey = $responseOldUrl !== '' ? $this->normalizeAttachmentRenameUrlKey($responseOldUrl) : '';
            $meta = ($attachmentId > 0 ? ($byAttachmentId[$attachmentId] ?? null) : null)
                ?? ($responseOldUrlKey !== '' ? ($byOldUrl[$responseOldUrlKey] ?? null) : null);

            if ($meta === null) {
                $enriched[] = $row;

                continue;
            }

            $existingBlockId = trim((string) ($row['block_id'] ?? $row['blockId'] ?? ''));
            if ($existingBlockId !== '') {
                $row['block_id'] = $existingBlockId;
            } elseif (($meta['block_id'] ?? '') !== '') {
                $row['block_id'] = $meta['block_id'];
            }

            if ($responseOldUrl === '' && ($meta['old_url'] ?? '') !== '') {
                $row['old_url'] = $meta['old_url'];
            }

            $enriched[] = $row;
        }

        return $enriched;
    }

    private function normalizeAttachmentRenameUrlKey(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return strtolower($url);
        }

        return strtolower($path);
    }

    /**
     * Cập nhật alt/title attachment trên WordPress (Media Library).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updateAttachmentMetaOnWordPress(array $items, bool $silent = false): void
    {
        $result = app(WordPressAttachmentMetaUpdateService::class)->updateBatch($this->record, $items);

        if ($silent) {
            return;
        }

        if ($result['success']) {
            Notification::make()
                ->title(__('seo-content-ai::filament.article_edit.image_alt_title_updated_wp'))
                ->body($result['message'])
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.article_edit.image_alt_title_update_wp_failed'))
            ->body($result['message'])
            ->danger()
            ->send();
    }

    /** @deprecated Chỉ dùng persistArticleLocal / syncArticleToWordPress từ nút sidebar */
    public function saveContent(string $html, bool $silent = false): void
    {
        if ($silent) {
            $this->persistArticleLocalSilent($html);

            return;
        }

        $this->persistArticleLocal($html);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeArticleMetaJson(string $key): ?array
    {
        /** @var ArticleMeta|null $meta */
        $meta = $this->record->articleMetas->firstWhere('meta_key', $key);
        if ($meta === null || ! is_string($meta->meta_value) || trim($meta->meta_value) === '') {
            return null;
        }

        $decoded = json_decode($meta->meta_value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function hydrateSeoMetaState(): void
    {
        $this->record->loadMissing('articleMetas');

        $this->record->articleMetas()->where('meta_key', 'seo_title')->delete();

        $this->seoTitle = trim($this->articleTitle);
        $this->seoTitleHydrated = $this->seoTitle;

        $this->seoMetaDescription = trim((string) (
            $this->record->articleMetas->first(
                static fn ($meta): bool => in_array((string) $meta->meta_key, [
                    'seo_meta_description',
                    'meta_description',
                ], true),
            )?->meta_value ?? ''
        ));

        $this->seoMetaDescriptionHydrated = $this->seoMetaDescription;

        $this->focusKeyword = app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($this->record) ?? '';
    }

    /**
     * Khôi phục tiêu đề / SEO meta từ revision vào Livewire (chưa ghi DB / WP).
     *
     * @param  array<string, mixed>  $seoMeta
     */
    public function applyRevisionPreviewToEditor(string $title, array $seoMeta = []): void
    {
        $this->articleTitle = trim($title);
        $this->seoTitle = trim($this->articleTitle);

        $this->seoMetaDescription = trim((string) ($seoMeta['meta_description'] ?? ''));
        $this->focusKeyword = trim((string) ($seoMeta['focus_keyword'] ?? ''));

        $slug = trim((string) ($seoMeta['slug'] ?? ''));
        if ($slug !== '') {
            $this->articleSlug = $slug;
        }
    }

    private function captureArticleRevisionAfterSave(string $html): void
    {
        app(SeoArticleRevisionService::class)->captureAfterSave(
            $this->record->fresh(),
            trim($this->articleTitle),
            $html,
            $this->buildRevisionSeoMetaSnapshot(),
            auth()->id() !== null ? (int) auth()->id() : null,
        );

        $this->dispatch('article-revisions-changed');
    }

    public function clearArticleRevisionHistory(): void
    {
        $articleId = (int) $this->record->getKey();
        $deleted = app(SeoArticleRevisionService::class)->clearAllForArticle($articleId);

        if ($deleted === 0) {
            Notification::make()
                ->title('Không có lịch sử')
                ->body('Bài viết chưa có phiên bản lịch sử nào.')
                ->info()
                ->send();

            return;
        }

        Notification::make()
            ->title('Đã dọn dẹp lịch sử')
            ->body("Đã xóa {$deleted} phiên bản lịch sử.")
            ->success()
            ->send();

        $this->dispatch('article-revisions-changed');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRevisionSeoMetaSnapshot(): array
    {
        $article = $this->record->fresh();

        return [
            'seo_title' => trim($this->articleTitle),
            'meta_description' => trim($this->seoMetaDescription),
            'focus_keyword' => trim($this->focusKeyword),
            'seo_score' => $article?->seoProfile?->seo_score !== null ? (float) $article->seoProfile?->seo_score : null,
            'slug' => trim($this->articleSlug),
        ];
    }

    private function persistSeoMetaFields(): void
    {
        $this->record->loadMissing('articleMetas');

        $seoDescription = trim($this->seoMetaDescription);

        foreach (['seo_meta_description', 'meta_description'] as $key) {
            if ($seoDescription === '') {
                if (trim($this->seoMetaDescriptionHydrated) !== '') {
                    $this->record->articleMetas()
                        ->where('meta_key', $key)
                        ->delete();
                }

                continue;
            }

            $this->record->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $seoDescription],
            );
        }

        $this->persistFocusKeyword();
    }

    private function persistFocusKeyword(): void
    {
        $siteId = (int) ($this->record->site_id ?? 0);
        if ($siteId <= 0) {
            return;
        }

        KeywordFocusAttach::syncMainKeyword(
            $this->record,
            $siteId,
            (int) auth()->id(),
            trim($this->focusKeyword),
        );

        // updateOrCreate/delete trên relation — phải bỏ cache để resolveFocusKeyword thấy keyword mới.
        $this->record->unsetRelation('articleMetas');
    }

    /**
     * @return array{seo_title: string, meta_description: string, focus_keyword: string}
     */
    private function resolveLivewireSeoPayloadForWordPress(): array
    {
        return [
            'seo_title' => '',
            'meta_description' => trim($this->seoMetaDescription),
            'focus_keyword' => trim($this->focusKeyword),
        ];
    }

    private function persistArticlePostTypeMeta(string $postType): void
    {
        $normalized = SeoProjectTask::normalizePostType($postType);

        $classification = ArticleContentClassification::fromTaskPostType($normalized);

        // Task vocabulary has no `page` label — keep content_type=page when the editor
        // still sends the legacy `article` label for an existing page.
        if ($normalized === SeoProjectTask::POST_TYPE_ARTICLE
            && ArticlePostTypeResolver::isPage($this->record)) {
            $classification['content_type'] = ContentType::Page;
            $classification['wp_post_type'] = 'page';
        }

        // Unchanged classification keeps its native slug (CPT machine stays machine);
        // switching type (article → product) lets the task vocabulary win.
        $current = ArticleContentClassification::for($this->record);
        if ($current->wpPostType() !== null
            && $current->contentType() === $classification['content_type']
            && $current->isTerm() === $classification['wp_is_term']
        ) {
            $classification['wp_post_type'] = $current->wpPostType();
        }

        // Editor never re-parents a term; keep the resolved hierarchy from sync.
        if ($classification['wp_is_term'] && $this->record->parent_id !== null) {
            unset($classification['parent_id']);
        }

        ArticleContentClassification::persist($this->record, $classification);

        if ($classification['wp_is_term']) {
            $this->record->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_taxonomy'],
                ['meta_value' => $classification['wp_post_type']],
            );
        } else {
            $this->record->articleMetas()->where('meta_key', 'wp_taxonomy')->delete();
        }

        $this->record->unsetRelation('articleMetas');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    private function syncPublishDatePartsFromRecord(): void
    {
        $dt = $this->resolvePublishAtForEditor() ?? SeoDisplayTimezone::now();

        $this->publishDay = $dt->format('d');
        $this->publishMonth = $dt->format('m');
        $this->publishYear = $dt->format('Y');
        $this->publishHour = $dt->format('H');
        $this->publishMinute = $dt->format('i');
    }

    private function resolvePublishAtForEditor(): ?Carbon
    {
        if ($this->record->publishingState?->published_at instanceof Carbon) {
            return $this->record->publishingState?->published_at->copy()->timezone(SeoDisplayTimezone::name());
        }

        return $this->buildPublishAtFromParts();
    }

    private function resolvePublishAtForSave(): ?Carbon
    {
        if ($this->articleStatus === 'draft') {
            return null;
        }

        $candidate = $this->buildPublishAtFromParts();
        if ($candidate !== null) {
            return $candidate;
        }

        if ($this->record->publishingState?->published_at instanceof Carbon) {
            return $this->record->publishingState?->published_at->copy()->timezone(SeoDisplayTimezone::name());
        }

        return SeoDisplayTimezone::now();
    }

    private function buildPublishAtFromParts(): ?Carbon
    {
        $year = (int) trim($this->publishYear);
        $month = (int) trim($this->publishMonth);
        $day = (int) trim($this->publishDay);
        $hour = (int) trim($this->publishHour);
        $minute = (int) trim($this->publishMinute);

        if (
            $year < 1970 || $year > 2100
            || $month < 1 || $month > 12
            || $day < 1 || $day > 31
            || $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59
        ) {
            return null;
        }

        try {
            return Carbon::createFromFormat(
                'Y-m-d H:i',
                sprintf('%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute),
                SeoDisplayTimezone::name(),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatWpScheduleLabel(Carbon $dt): string
    {
        return SeoDisplayTimezone::formatScheduleLabel($dt);
    }
}
