<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Providers;

use Omnichannel\Addons\Seo\Filament\Pages\Auth\SeoChangePassword;
use Omnichannel\Addons\Seo\Filament\Pages\Auth\SeoEditProfile;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorLazyPayloadController;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSyncController;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorSessionController;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorOperationController;
use Omnichannel\Addons\Commerce\Http\Controllers\ArticleProductReviewReconcileController;
use Omnichannel\Addons\Commerce\Http\Controllers\ArticleProductReviewStatusController;
use Omnichannel\Addons\Content\Http\Controllers\ArticleReviewActionController;
use Omnichannel\Addons\Social\Http\Controllers\ArticleSocialLinkController;
use Omnichannel\Addons\Seeding\Http\Controllers\SeedingTopicController;
use Omnichannel\Addons\WordPress\Http\Controllers\ArticleWordPressProductReviewsController;
use Omnichannel\Addons\Media\Http\Controllers\ArticleMediaPickerController;
use Omnichannel\Addons\Content\Http\Controllers\ArticleOutlineController;
use Omnichannel\Addons\Content\Http\Controllers\ArticlePreviewController;
use Omnichannel\Addons\Content\Http\Controllers\ArticleRevisionController;
use Omnichannel\Addons\Content\Http\Controllers\ArticleSeoPreviewController;
use Omnichannel\Addons\Seo\Http\Controllers\ArticleSeoScorePreviewController;
use Omnichannel\Addons\WordPress\Http\Controllers\ArticleWpEditRedirectController;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\GoogleSearchConsoleOAuthController;
use Omnichannel\Addons\SearchFoundation\Http\Controllers\KeywordReviewController;
use Omnichannel\Addons\AiPrompt\Http\Controllers\PromptHookExecuteController;
use Omnichannel\Addons\Content\Http\Controllers\SeoArticleRevisionController;
use Omnichannel\Addons\Media\Http\Controllers\SeoMediaController;
use Omnichannel\Addons\Seo\Filament\Pages\Auth\SeoLogin;
use Omnichannel\Addons\Seo\Http\Controllers\SeoLoginController;
use Omnichannel\Addons\Seo\Http\Controllers\SeoPanelLogoutController;
use Omnichannel\Addons\Seo\Http\Controllers\SeoPanelRedirectController;
use Omnichannel\Addons\Seo\Http\Middleware\BootFilamentSeoPanel;
use Omnichannel\Addons\Seo\Http\Middleware\ResolveSeoMainServiceContext;
use Omnichannel\Addons\Media\Http\Controllers\SeoWatermarkController;
use Omnichannel\Addons\Seo\Http\Controllers\SupportTicketController;
use Omnichannel\Addons\Seo\Http\Controllers\TeamMessageController;
use Omnichannel\Addons\Media\Http\Controllers\WorkspaceMediaPickerController;
use Omnichannel\Addons\Seo\Http\Middleware\CheckMainRole;
use Omnichannel\Addons\Seo\Http\Middleware\SeoAuthenticate;
use Omnichannel\Addons\Seo\Http\Middleware\SeoPlannerPermissionMiddleware;
use Omnichannel\Addons\SearchFoundation\Http\Middleware\SetDynamicSeoDatabase;
use Omnichannel\Addons\AiPrompt\Services\PromptMediaStorageService;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Http\Middleware\SetDynamicSeoDatabaseByHash;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Middleware\Authenticate as IlluminateAuthenticate;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

/**
 * COMPATIBILITY BOOTSTRAP ONLY — Filament SEO panel registration.
 * Page/resource/controller classes already live in peer Omnichannel\Addons\* packages.
 * See app/Addons/SeoContentAi/README.md and docs/architecture/SEOCONTENTAI_CUTOVER_INVENTORY.json.
 */
class SeoPanelProvider extends PanelProvider
{
    public function register(): void
    {
        // BEFORE panel registration — Filament binds seo/{connection_hash} for the hash panel.
        Route::pattern('connection_hash', '[a-zA-Z0-9]{32,64}');

        // Hash/secondary panel keeps id "seo" so existing filament.seo.* route names stay stable.
        parent::register();

        // Main Service short URLs: real /seo/{page} routes (no REQUEST_URI rewrite).
        Filament::registerPanel(
            fn (): Panel => $this->configureSeoPanel(
                Panel::make()->id('seo-main')->path('seo'),
                hashed: false,
            ),
        );

        // Shared persistTarget for usingTargetMedia() across PromptRunner / GeminiMediaGenerationService.
        $this->app->singleton(PromptMediaStorageService::class);
        $this->app->singleton(DomainContextResolver::class);
    }

    public function boot(): void
    {
        $addonRoot = dirname(__DIR__);

        Livewire::component('global-seo-bar', \Omnichannel\Addons\Seo\Livewire\GlobalSeoBar::class);
        Livewire::component(
            'assign-to-content-project-drawer',
            \Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer::class,
        );
        // Compatibility alias — same canonical drawer component.
        Livewire::component(
            'assign-to-content-project-modal',
            \Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer::class,
        );

        $this->loadViewsFrom($addonRoot.'/resources/views', 'seo-content-ai');
        $this->loadTranslationsFrom($addonRoot.'/lang', 'seo-content-ai');

        // Short login POST (Filament seo-main owns GET /seo/login).
        // Hash login POST remains explicit — Filament hash panel only registers GET login.
        Route::middleware(['web'])
            ->group(function (): void {
                Route::post('/seo/login', [SeoLoginController::class, 'store'])
                    ->middleware([BootFilamentSeoPanel::class])
                    ->name('seo.auth.login.store');
                Route::post('/seo/{connection_hash}/login', [SeoLoginController::class, 'store'])
                    ->middleware([BootFilamentSeoPanel::class])
                    ->where(['connection_hash' => '[a-zA-Z0-9]{32,64}'])
                    ->name('seo.auth.login.hash.store');
            });

        Route::middleware(['web'])
            ->get('/seo/select-workspace', SeoPanelRedirectController::class)
            ->name('seo.panel.redirect');

        // Exact /seo stays Filament seo-main Dashboard (registered by panel path "seo").
        // Guests are sent to /seo/login by auth middleware.

        Route::middleware(['web', 'auth'])
            ->post('/seo/logout', SeoPanelLogoutController::class)
            ->name('filament.seo-main.auth.logout');

        Filament::serving(function (): void {
            $panelId = filament()->getCurrentPanel()?->getId();
            if (! in_array($panelId, ['seo', 'seo-main'], true)) {
                return;
            }

            $hash = SeoConnectionContext::applyUrlDefaultsFromRequest();

            if ($hash !== null) {
                try {
                    app(SeoDatabaseConnectionService::class)->bootstrapByHash($hash);
                } catch (\RuntimeException) {
                    $siteId = SeoAccessControl::globalSiteId();
                    if ($siteId !== null && $siteId > 0) {
                        app(SeoDatabaseConnectionService::class)->bootstrapBySiteId($siteId);
                    }
                }
            } elseif (($siteId = SeoAccessControl::globalSiteId()) !== null && $siteId > 0) {
                app(SeoDatabaseConnectionService::class)->bootstrapBySiteId($siteId);
            }

            SeoConnectionContext::applyUrlDefaultsFromRequest();
        });

        FilamentView::registerRenderHook(
            'panels::global-search.after',
            function (): string {
                if (! request()->is('seo', 'seo/*')) {
                    return '';
                }

                if (! SeoAccessControl::shouldShowGlobalSeoBar()) {
                    return '';
                }

                return Blade::render('@livewire(\'global-seo-bar\')');
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            function (): HtmlString {
                if (! auth()->check() || ! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.hooks.global-help-trigger')->render()
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): HtmlString {
                if (! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.hooks.system-datetime-bootstrap')->render()
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): HtmlString {
                if (! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.hooks.bulk-select-reload-guard')->render()
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): HtmlString {
                if (! auth()->check() || ! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                if (! SeoAccessControl::shouldShowGlobalSeoBar()) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo::hooks.domain-context-assets')->render()
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): HtmlString {
                if (! auth()->check() || ! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    \Illuminate\Support\Facades\Blade::render('@livewire(\'assign-to-content-project-drawer\')')
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): HtmlString {
                if (! auth()->check() || ! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.hooks.global-help-modal')->render()
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): HtmlString {
                if (! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.prompt-variable-insert')->render()
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): HtmlString {
                if (! auth()->check() || ! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                // Floating Chat Workspace retired — Chat lives at /seo/{hash}/chat only.
                // Global pages may mount lightweight unread badge (sidebar), never message poll.
                return new HtmlString(
                    view('seo-content-ai::components.workspace-media-picker')->render()
                    .view('seo-content-ai::components.chat-unread-badge')->render(),
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_FOOTER,
            function (): HtmlString {
                if (! in_array(filament()->getCurrentPanel()?->getId(), ['seo', 'seo-main'], true)) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.hooks.seo-sidebar-footer')->render()
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            function (): HtmlString {
                if (! in_array(filament()->getCurrentPanel()?->getId(), ['seo', 'seo-main'], true) && ! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.hooks.seo-sidebar-collapsed')->render()
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            function (): HtmlString {
                if (! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    '<script>'
                    .'window.__SEO_I18N_LOCALE__ = '.json_encode(app()->getLocale()).';'
                    .'window.__SEO_CONNECTION_HASH__ = '.json_encode(SeoConnectionContext::hash()).';'
                    .'document.documentElement.setAttribute("lang", '.json_encode(str_replace('_', '-', app()->getLocale())).');'
                    .'</script>'
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            function (): HtmlString {
                if (! auth()->check() || ! request()->is('seo', 'seo/*')) {
                    return new HtmlString('');
                }

                return new HtmlString(
                    view('seo-content-ai::filament.hooks.global-help-assets')->render()
                );
            },
        );

        Route::middleware('api')
            ->prefix('api')
            ->group(dirname(__DIR__).'/routes/api.php');

        $seoWebApiMiddleware = [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            IlluminateAuthenticate::class,
            CheckMainRole::class,
            SetDynamicSeoDatabase::class,
            SubstituteBindings::class,
        ];

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/seo/media')
            ->group(function (): void {
                Route::get('/workspace-picker', WorkspaceMediaPickerController::class)
                    ->name('seo.media.workspace-picker');
                Route::post('/upload', [SeoMediaController::class, 'upload'])
                    ->name('seo.media.upload');
                Route::post('/import-url', [SeoMediaController::class, 'importFromUrl'])
                    ->name('seo.media.import-url');
                Route::post('/rename-by-url', [SeoMediaController::class, 'renameByUrl'])
                    ->name('seo.media.rename-by-url');
                Route::get('/splitter-source', [SeoMediaController::class, 'splitterSource'])
                    ->name('seo.media.splitter-source');
                Route::post('/save-split', [SeoMediaController::class, 'saveSplit'])
                    ->name('seo.media.save-split');
                Route::post('/prepare-editor', [SeoMediaController::class, 'prepareEditor'])
                    ->name('seo.media.prepare-editor');
                Route::post('/apply-watermark', [SeoMediaController::class, 'applyWatermark'])
                    ->name('seo.media.apply-watermark');
                Route::post('/test-optimize-local-webp', [SeoMediaController::class, 'testOptimizeLocalWebp'])
                    ->name('seo.media.test-optimize-local-webp');
                Route::get('/article/{article}/ai-jobs', [SeoMediaController::class, 'articleAiJobs'])
                    ->whereNumber('article')
                    ->name('seo.media.article-ai-jobs');
                Route::get('/{media}/status', [SeoMediaController::class, 'status'])
                    ->whereNumber('media')
                    ->name('seo.media.status');
                Route::post('/{media}/retry-generation', [SeoMediaController::class, 'retryGeneration'])
                    ->whereNumber('media')
                    ->name('seo.media.retry-generation');
                Route::delete('/{media}/ai-job', [SeoMediaController::class, 'deleteAiJob'])
                    ->whereNumber('media')
                    ->name('seo.media.delete-ai-job');
                Route::post('/wordpress/rename/preview', [\Omnichannel\Addons\WordPress\Http\Controllers\WordPressMediaRenameController::class, 'preview'])
                    ->name('seo.media.wordpress.rename.preview');
                Route::post('/wordpress/rename', [\Omnichannel\Addons\WordPress\Http\Controllers\WordPressMediaRenameController::class, 'rename'])
                    ->name('seo.media.wordpress.rename');
                Route::post('/{media}/rename', [SeoMediaController::class, 'rename'])
                    ->whereNumber('media')
                    ->name('seo.media.rename');
                Route::post('/update-meta', [SeoMediaController::class, 'updateMeta'])
                    ->name('seo.media.update-meta');
                Route::post('/{media}/save-edited', [SeoMediaController::class, 'saveEditedImage'])
                    ->whereNumber('media')
                    ->name('seo.media.save-edited');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/seo/prompt-hooks')
            ->group(function (): void {
                Route::post('/{hookKey}/execute', PromptHookExecuteController::class)
                    ->where('hookKey', '[A-Za-z0-9_.-]+')
                    ->name('seo.prompt-hooks.execute');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/seo')
            ->group(function (): void {
                Route::get('/domain-cta/quick-templates', [\Omnichannel\Addons\SearchFoundation\Http\Controllers\DomainCtaQuickTemplatesController::class, 'show'])
                    ->name('seo.domain-cta.quick-templates.show');
                Route::put('/domain-cta/quick-templates', [\Omnichannel\Addons\SearchFoundation\Http\Controllers\DomainCtaQuickTemplatesController::class, 'update'])
                    ->name('seo.domain-cta.quick-templates.update');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/seo/articles')
            ->group(function (): void {
                Route::post('/{article}/edit-lease', [ArticleEditorSessionController::class, 'store'])
                    ->whereNumber('article')
                    ->name('seo.articles.edit-lease.store');
                Route::put('/{article}/edit-lease/{session}', [ArticleEditorSessionController::class, 'heartbeat'])
                    ->whereNumber('article')
                    ->name('seo.articles.edit-lease.renew');
                Route::delete('/{article}/edit-lease/{session}', [ArticleEditorSessionController::class, 'destroy'])
                    ->whereNumber('article')
                    ->name('seo.articles.edit-lease.destroy');
                // Compatibility aliases for clients deployed before the edit-lease cutover.
                Route::post('/{article}/editor-sessions', [ArticleEditorSessionController::class, 'store'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor-sessions.store');
                Route::get('/{article}/outline', [ArticleOutlineController::class, 'index'])
                    ->whereNumber('article')
                    ->name('seo.articles.outline.index');
                Route::post('/{article}/outline', [ArticleOutlineController::class, 'store'])
                    ->whereNumber('article')
                    ->name('seo.articles.outline.store');
                Route::post('/{article}/outline/check-duplicates', [ArticleOutlineController::class, 'checkDuplicates'])
                    ->whereNumber('article')
                    ->name('seo.articles.outline.check-duplicates'); // deprecated no-op; do not remove without proving zero callers
                Route::post('/{article}/outline/refresh', [ArticleOutlineController::class, 'refresh'])
                    ->whereNumber('article')
                    ->name('seo.articles.outline.refresh');
                Route::put('/{article}/outline/{heading}', [ArticleOutlineController::class, 'update'])
                    ->whereNumber('article')
                    ->whereNumber('heading')
                    ->name('seo.articles.outline.update');
                Route::delete('/{article}/outline/{heading}', [ArticleOutlineController::class, 'destroy'])
                    ->whereNumber('article')
                    ->whereNumber('heading')
                    ->name('seo.articles.outline.destroy');
                Route::post('/{article}/outline/{heading}/generate', [ArticleOutlineController::class, 'generate'])
                    ->whereNumber('article')
                    ->whereNumber('heading')
                    ->name('seo.articles.outline.generate');
                Route::get('/{article}/revisions', [ArticleRevisionController::class, 'index'])
                    ->whereNumber('article')
                    ->name('seo.articles.revisions.index');
                Route::post('/{article}/save', [ArticleEditorSyncController::class, 'save'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.save');
                Route::post('/{article}/editor-sessions/takeover', [ArticleEditorSessionController::class, 'takeover'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor-sessions.takeover');
                Route::put('/{article}/editor-sessions/{session}/heartbeat', [ArticleEditorSessionController::class, 'heartbeat'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor-sessions.heartbeat');
                Route::put('/{article}/editor-sessions/{session}/document', [ArticleEditorSessionController::class, 'document'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor-sessions.document');
                Route::post('/{article}/editor-sessions/{session}/close', [ArticleEditorSessionController::class, 'close'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor-sessions.close');
                Route::delete('/{article}/editor-sessions/{session}', [ArticleEditorSessionController::class, 'destroy'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor-sessions.destroy');
                Route::post('/{article}/seo-meta', [ArticleEditorSyncController::class, 'saveSeoMeta'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.seo-meta');
                Route::post('/{article}/seo-score/preview', ArticleSeoScorePreviewController::class)
                    ->whereNumber('article')
                    ->name('seo.articles.seo-score.preview');
                Route::get('/{article}/editor-seo-payload', [ArticleEditorSyncController::class, 'seoPayload'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.seo-payload');
                // Phase 2 lazy bootstrap endpoints — fetched by the client after core mount / on panel open.
                Route::get('/{article}/editor/seo-summary', [ArticleEditorLazyPayloadController::class, 'seoSummary'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.seo-summary');
                Route::get('/{article}/editor/images', [ArticleEditorLazyPayloadController::class, 'images'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.images');
                Route::get('/{article}/editor/faqs', [ArticleEditorLazyPayloadController::class, 'faqs'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.faqs');
                Route::get('/{article}/editor/faqs/count', [ArticleEditorLazyPayloadController::class, 'faqsCount'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.faqs-count');
                Route::get('/{article}/editor/faq-snapshot', [\Omnichannel\Addons\Content\Http\Controllers\ArticleEditorFaqSnapshotController::class, 'show'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.faq-snapshot');
                Route::put('/{article}/editor/faq-snapshot', [\Omnichannel\Addons\Content\Http\Controllers\ArticleEditorFaqSnapshotController::class, 'replace'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.faq-snapshot.replace');
                Route::post('/{article}/editor/faq-snapshot/generate-preview', [\Omnichannel\Addons\Content\Http\Controllers\ArticleEditorFaqSnapshotController::class, 'generatePreview'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.faq-snapshot.generate-preview');
                Route::post('/{article}/editor/faq-snapshot/apply', [\Omnichannel\Addons\Content\Http\Controllers\ArticleEditorFaqSnapshotController::class, 'apply'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.faq-snapshot.apply');
                Route::post('/{article}/editor/faq-snapshot/extract', [\Omnichannel\Addons\Content\Http\Controllers\ArticleEditorFaqSnapshotController::class, 'extract'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.faq-snapshot.extract');
                Route::get('/{article}/editor/meta', [ArticleEditorLazyPayloadController::class, 'meta'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.meta');
                Route::get('/{article}/editor/links', [ArticleEditorLazyPayloadController::class, 'links'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.links');
                Route::get('/{article}/editor/vocabulary', [ArticleEditorLazyPayloadController::class, 'vocabulary'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.vocabulary');
                Route::get('/{article}/editor/links/suggestions', [ArticleEditorLazyPayloadController::class, 'linksSuggestions'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.links-suggestions');
                Route::post('/{article}/editor/links/suggestions', [ArticleEditorLazyPayloadController::class, 'linksSuggestions'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.links-suggestions.post');
                Route::get('/{article}/editor/settings', [ArticleEditorLazyPayloadController::class, 'settings'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.settings');
                Route::post('/{article}/editor/media-prompt-preview', [ArticleEditorLazyPayloadController::class, 'mediaPromptPreview'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.media-prompt-preview');
                Route::get('/{article}/editor/media-picker-config', [ArticleEditorLazyPayloadController::class, 'mediaPickerConfig'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.media-picker-config');
                Route::get('/{article}/editor/media-snapshot', [\Omnichannel\Addons\Media\Http\Controllers\ArticleEditorMediaSnapshotController::class, 'show'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.media-snapshot');
                Route::put('/{article}/editor/media/featured', [\Omnichannel\Addons\Media\Http\Controllers\ArticleEditorMediaSnapshotController::class, 'setFeatured'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.media.featured.set');
                Route::delete('/{article}/editor/media/featured', [\Omnichannel\Addons\Media\Http\Controllers\ArticleEditorMediaSnapshotController::class, 'clearFeatured'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.media.featured.clear');
                Route::put('/{article}/editor/media/gallery', [\Omnichannel\Addons\Media\Http\Controllers\ArticleEditorMediaSnapshotController::class, 'replaceGallery'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.media.gallery.replace');
                Route::post('/{article}/editor/media/gallery/reorder', [\Omnichannel\Addons\Media\Http\Controllers\ArticleEditorMediaSnapshotController::class, 'reorderGallery'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.media.gallery.reorder');
                Route::post('/{article}/sync-wp', [ArticleEditorSyncController::class, 'syncWp'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.sync-wp');
                Route::get('/{article}/operation-status', [ArticleEditorOperationController::class, 'status'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.operation-status');
                Route::post('/{article}/fix-media-slugs', [ArticleEditorOperationController::class, 'fixMediaSlugs'])
                    ->whereNumber('article')
                    ->name('seo.articles.editor.fix-media-slugs');
                Route::get('/{article}/wordpress-product-reviews', ArticleWordPressProductReviewsController::class)
                    ->whereNumber('article')
                    ->name('seo.articles.wordpress-product-reviews');
                Route::get('/{article}/product-review-status', [ArticleProductReviewStatusController::class, 'status'])
                    ->whereNumber('article')
                    ->name('seo.articles.product-review-status');
                Route::post('/{article}/product-reviews/create', [ArticleProductReviewStatusController::class, 'create'])
                    ->whereNumber('article')
                    ->name('seo.articles.product-reviews.create');
                Route::post('/{article}/product-reviews/sync', [ArticleProductReviewStatusController::class, 'sync'])
                    ->whereNumber('article')
                    ->name('seo.articles.product-reviews.sync');
                Route::post('/{article}/product-reviews/reconcile', ArticleProductReviewReconcileController::class)
                    ->whereNumber('article')
                    ->name('seo.articles.product-reviews.reconcile');
                Route::get('/{article}/revisions/{revision}', [SeoArticleRevisionController::class, 'show'])
                    ->whereNumber('article')
                    ->whereNumber('revision')
                    ->name('seo.articles.revisions.show');
                Route::get('/{article}/review-actions', [ArticleReviewActionController::class, 'show'])
                    ->whereNumber('article')
                    ->name('seo.articles.review-actions.show');
                Route::post('/{article}/review-actions', [ArticleReviewActionController::class, 'store'])
                    ->whereNumber('article')
                    ->name('seo.articles.review-actions.store');
                Route::get('/{article}/social-links', [ArticleSocialLinkController::class, 'index'])
                    ->whereNumber('article')
                    ->name('seo.articles.social-links.index');
                Route::post('/{article}/social-links', [ArticleSocialLinkController::class, 'store'])
                    ->whereNumber('article')
                    ->name('seo.articles.social-links.store');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/seo/keywords')
            ->group(function (): void {
                Route::get('/review-reasons', [KeywordReviewController::class, 'reasons'])
                    ->name('seo.keywords.review-reasons');
                Route::post('/ensure-for-review', [KeywordReviewController::class, 'ensureForReview'])
                    ->name('seo.keywords.ensure-for-review');
                Route::post('/{keyword}/review', [KeywordReviewController::class, 'review'])
                    ->whereNumber('keyword')
                    ->name('seo.keywords.review');
                Route::post('/{keyword}/toggle-error', [KeywordReviewController::class, 'toggleError'])
                    ->whereNumber('keyword')
                    ->name('seo.keywords.toggle-error');
                Route::post('/{keyword}/restore', [KeywordReviewController::class, 'restore'])
                    ->whereNumber('keyword')
                    ->name('seo.keywords.restore');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/seo/seeding-topics')
            ->group(function (): void {
                Route::get('/', [SeedingTopicController::class, 'index'])
                    ->name('seo.seeding-topics.index');
                Route::post('/', [SeedingTopicController::class, 'store'])
                    ->name('seo.seeding-topics.store');
                Route::get('/{topic}', [SeedingTopicController::class, 'show'])
                    ->whereNumber('topic')
                    ->name('seo.seeding-topics.show');
                Route::patch('/{topic}', [SeedingTopicController::class, 'update'])
                    ->whereNumber('topic')
                    ->name('seo.seeding-topics.update');
                Route::delete('/{topic}', [SeedingTopicController::class, 'destroy'])
                    ->whereNumber('topic')
                    ->name('seo.seeding-topics.destroy');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('api/seo/watermark')
            ->group(function (): void {
                Route::get('/settings', [SeoWatermarkController::class, 'showSettings'])
                    ->name('seo.watermark.settings.show');
                Route::post('/settings', [SeoWatermarkController::class, 'saveSettings'])
                    ->name('seo.watermark.settings.save');
                Route::post('/batch', [SeoWatermarkController::class, 'applyBatch'])
                    ->name('seo.watermark.batch');
                Route::post('/media/{media}/save', [SeoWatermarkController::class, 'saveMediaWatermark'])
                    ->whereNumber('media')
                    ->name('seo.watermark.media.save');
                Route::post('/save-new', [SeoWatermarkController::class, 'saveNewFromCanvas'])
                    ->name('seo.watermark.save-new');
            });

        $seoTeamApiMiddleware = [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            IlluminateAuthenticate::class,
            SetDynamicSeoDatabase::class,
            SubstituteBindings::class,
        ];

        Route::middleware($seoTeamApiMiddleware)
            ->prefix('api/seo/team')
            ->group(function (): void {
                Route::get('/messages', [TeamMessageController::class, 'index'])
                    ->name('seo.team-messages.index');
                Route::get('/unread-count', [TeamMessageController::class, 'unreadCount'])
                    ->name('seo.team-messages.unread-count');
                Route::post('/mark-read', [TeamMessageController::class, 'markRead'])
                    ->name('seo.team-messages.mark-read');
                Route::get('/config', [TeamMessageController::class, 'config'])
                    ->name('seo.team-messages.config');
                Route::post('/messages', [TeamMessageController::class, 'store'])
                    ->name('seo.team-messages.store');
            });

        Route::middleware($seoTeamApiMiddleware)
            ->prefix('api/seo/support-tickets')
            ->group(function (): void {
                Route::get('/', [SupportTicketController::class, 'index'])
                    ->name('seo.support-tickets.index');
                Route::post('/', [SupportTicketController::class, 'store'])
                    ->name('seo.support-tickets.store');
                Route::post('/{id}/retry', [SupportTicketController::class, 'retry'])
                    ->whereNumber('id')
                    ->name('seo.support-tickets.retry');
            });

        // Floating Global AI Chat retired. Canonical communication UI: /seo/{hash}/chat.

        Route::middleware($seoWebApiMiddleware)
            ->prefix('seo/oauth/google-search-console')
            ->group(function (): void {
                Route::get('/callback', [GoogleSearchConsoleOAuthController::class, 'callback'])
                    ->name('seo.gsc.oauth.callback');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('seo/{connection_hash}')
            ->where(['connection_hash' => '[a-zA-Z0-9]{32,64}'])
            ->group(function (): void {
                Route::get('/settings/api/google-search-console/{record}/connect', [GoogleSearchConsoleOAuthController::class, 'redirect'])
                    ->whereNumber('record')
                    ->name('seo.gsc.oauth.redirect');

                Route::get('/articles/wp-edit-redirect', ArticleWpEditRedirectController::class)
                    ->name('seo.articles.wp-edit-redirect');

                Route::redirect('/keywords/workspace-3', '../keywords/ai-discovery')
                    ->name('seo.keywords.workspace-3-legacy');
                Route::redirect('/settings/ai', '../settings/api')
                    ->name('seo.settings.ai-legacy');
                Route::redirect('/settings/ai/create', '../settings/api/create')
                    ->name('seo.settings.ai-create-legacy');
                Route::get('/settings/ai/{record}/edit', function (string $connection_hash, string $record) {
                    return redirect("../settings/api/{$record}/edit");
                })->whereNumber('record')->name('seo.settings.ai-edit-legacy');
            });

        Route::middleware($seoWebApiMiddleware)
            ->prefix('seo')
            ->group(function (): void {
                Route::get('/articles/{article}/media-picker', ArticleMediaPickerController::class)
                    ->whereNumber('article')
                    ->name('seo.articles.media-picker');
                Route::get('/articles/{article}/seo-preview', ArticleSeoPreviewController::class)
                    ->name('seo.articles.seo-preview');
                Route::get('/articles/{article}/preview', ArticlePreviewController::class)
                    ->name('seo.articles.preview');
                Route::get('/articles/{article}/revisions', [SeoArticleRevisionController::class, 'compare'])
                    ->whereNumber('article')
                    ->name('seo.articles.revisions.compare');
                Route::post('/articles/{article}/revisions/restore', [SeoArticleRevisionController::class, 'restore'])
                    ->whereNumber('article')
                    ->name('seo.articles.revisions.restore');
                Route::get('/articles/wp-edit-redirect', ArticleWpEditRedirectController::class)
                    ->name('seo.articles.wp-edit-redirect.legacy');
            });

    }

    public function panel(Panel $panel): Panel
    {
        return $this->configureSeoPanel(
            $panel->id('seo')->path('seo/{connection_hash}'),
            hashed: true,
        );
    }

    private function configureSeoPanel(Panel $panel, bool $hashed): Panel
    {
        $panel = $panel
            ->profile(SeoEditProfile::class, isSimple: false)
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.ico'))
            ->userMenuItems([
                'profile' => MenuItem::make()
                    ->label(fn (): string => filament()->getUserName(Filament::auth()->user()))
                    ->url(fn (): string => SeoEditProfile::getUrl())
                    ->icon('heroicon-m-user-circle'),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16rem')
            ->collapsedSidebarWidth('4rem')
            ->maxContentWidth(MaxWidth::Full)
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make(\Omnichannel\Addons\Seo\Support\SeoUserNavigation::GROUP_SYSTEM),
            ])
            ->discoverResources(
                in: __DIR__.'/../Filament/Resources',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Resources'
            )
            ->discoverPages(
                in: __DIR__.'/../Filament/Pages',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Pages'
            )
            ->discoverWidgets(
                in: __DIR__.'/../Filament/Widgets',
                for: 'App\\Addons\\SeoContentAi\\Filament\\Widgets'
            );

        if ($hashed) {
            $panel = $panel->login(SeoLogin::class);
        } else {
            // Short Main panel needs Filament auth routes (logout/profile) without URI rewrite.
            // Login page lives at GET /seo/login (same SeoLogin class; no connection_hash).
            $panel = $panel
                ->login(SeoLogin::class)
                ->homeUrl(static fn (): string => url('/seo'));
        }

        foreach ($this->peerFilamentDiscoveries() as $discovery) {
            if (is_dir($discovery['resources'])) {
                $panel = $panel->discoverResources(in: $discovery['resources'], for: $discovery['resourcesNs']);
            }
            if (is_dir($discovery['pages'])) {
                $panel = $panel->discoverPages(in: $discovery['pages'], for: $discovery['pagesNs']);
            }
            if (is_dir($discovery['widgets'])) {
                $panel = $panel->discoverWidgets(in: $discovery['widgets'], for: $discovery['widgetsNs']);
            }
        }

        $contextMiddleware = $hashed
            ? SetDynamicSeoDatabaseByHash::class
            : ResolveSeoMainServiceContext::class;

        return $panel
            ->pages([
                SeoChangePassword::class,
            ])
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                $contextMiddleware,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetDynamicSeoDatabase::class,
            ])
            ->authMiddleware([
                SeoAuthenticate::class,
                CheckMainRole::class,
                SeoPlannerPermissionMiddleware::class,
            ]);
    }

    /**
     * @return list<array{resources:string,pages:string,widgets:string,resourcesNs:string,pagesNs:string,widgetsNs:string}>
     */
    private function peerFilamentDiscoveries(): array
    {
        $map = [
            'search-foundation' => 'SearchFoundation',
            'seo' => 'Seo',
            'search-intelligence' => 'SearchIntelligence',
            'ai-prompt' => 'AiPrompt',
            'content' => 'Content',
            'content-projects' => 'ContentProjects',
            'media' => 'Media',
            'wordpress' => 'WordPress',
            'publishing' => 'Publishing',
            'site-sync' => 'SiteSync',
            'agent' => 'Agent',
            'commerce' => 'Commerce',
            'social' => 'Social',
            'seeding' => 'Seeding',
        ];

        $out = [];
        foreach ($map as $slug => $pascal) {
            $base = base_path('addons/'.$slug.'/src/Filament');
            $out[] = [
                'resources' => $base.'/Resources',
                'pages' => $base.'/Pages',
                'widgets' => $base.'/Widgets',
                'resourcesNs' => 'Omnichannel\\Addons\\'.$pascal.'\\Filament\\Resources',
                'pagesNs' => 'Omnichannel\\Addons\\'.$pascal.'\\Filament\\Pages',
                'widgetsNs' => 'Omnichannel\\Addons\\'.$pascal.'\\Filament\\Widgets',
            ];
        }

        return $out;
    }
}
