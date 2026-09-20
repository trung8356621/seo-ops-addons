<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationFlags;
use Omnichannel\Addons\Agent\Automation\Migration\AutomationMigrationWriteException;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleContentCallerBridge;
use Omnichannel\Addons\Agent\Automation\Migration\ProjectArticleSeoMetaCallerBridge;
use Omnichannel\Addons\Agent\Automation\Runtime\ActionRunner;
use Omnichannel\Addons\Agent\Automation\Support\ArticleContentConflictGuard;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\AiGeneratedContentNormalizer;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleCtaPlaceholderService;
use Omnichannel\Addons\Content\Services\ArticleEditorPersistService;
use Omnichannel\Addons\Content\Services\ArticleEditorReadinessService;
use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Services\SeoFaqPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\Content\Support\MarkdownOutlineParser;
use Omnichannel\Addons\SearchFoundation\Support\MarkdownSemanticKeywordsParser;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Illuminate\Support\Str;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;
use Omnichannel\Addons\AiPrompt\Contracts\ArticleBodyPublishPort;

final class PromptTestPublishService implements ArticleBodyPublishPort
{
    /**
     * @internal Testing only — intercept guarded body write (final bridges cannot be mocked).
     *
     * @var null|callable(array<string, mixed>, array<string, mixed>, callable(): array<string, mixed>, callable(): ActionResult, ?string): mixed
     */
    private $bodyWriteInterceptor = null;

    public function __construct(
        private readonly MarkdownOutlineParser $outlineParser,
        private readonly MarkdownSemanticKeywordsParser $keywordsParser,
        private readonly ArticleMarkdownToHtmlService $markdownHtml,
        private readonly ProjectArticleContentCallerBridge $contentBridge,
        private readonly ProjectArticleSeoMetaCallerBridge $seoMetaBridge,
        private readonly ActionRunner $actionRunner,
        private readonly AutomationMigrationFlags $migrationFlags,
        private readonly ArticleContentConflictGuard $contentConflictGuard,
    ) {}

    /**
     * @internal Testing only.
     *
     * @param  null|callable(array<string, mixed>, array<string, mixed>, callable(): array<string, mixed>, callable(): ActionResult, ?string): mixed  $interceptor
     */
    public function interceptBodyWriteForTests(?callable $interceptor): void
    {
        $this->bodyWriteInterceptor = $interceptor;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{success: bool, message: string}
     */
    public function publishSkeleton(SeoArticle $article, string $aiOutput, array $variables = []): array
    {
        $markdown = trim($aiOutput);
        if ($markdown === '') {
            return ['success' => false, 'message' => 'Kết quả AI trống.'];
        }

        $this->persistOutlineAndKeywords($article, $markdown);
        $this->syncFocusKeyword($article, $variables, $markdown);

        return [
            'success' => true,
            'message' => 'Đã lưu sườn bài (dàn ý + từ khóa ngữ nghĩa) vào meta bài viết #'.$article->id.'.',
        ];
    }

    /**
     * Canonical markdown → HTML pipeline shared by publish + persist verification.
     *
     * @return array{
     *     markdown: string,
     *     html: string,
     *     content_hash: string,
     *     faqs: list<array<string, mixed>>,
     *     meta_description: ?string,
     *     h1_title: string
     * }
     */
    public function prepareArticleContent(SeoArticle $article, string $aiOutput): array
    {
        $markdown = trim($aiOutput);
        if ($markdown === '') {
            throw new \InvalidArgumentException('Kết quả AI trống.');
        }

        $import = app(ArticleContentFaqService::class)->convertMarkdownImport($markdown);
        $cta = app(ArticleCtaPlaceholderService::class)->applyForPublish(
            (int) $article->site_id > 0 ? (int) $article->site_id : null,
            $import['html'],
            $import['faqs'],
        );
        $html = app(AiGeneratedContentNormalizer::class)->normalizeHtml($cta['html']);
        // Hash + write must use the same representation articles.body stores
        // (stripTransientEditorMarkup / CTA blank unwrap). Writer re-runs this
        // canonicalize idempotently.
        $html = app(ArticleEditorPersistService::class)->canonicalizeBodyForPersist($html);

        return [
            'markdown' => $markdown,
            'html' => $html,
            'content_hash' => $this->contentConflictGuard->contentHash($html),
            'faqs' => is_array($cta['faqs'] ?? null) ? $cta['faqs'] : [],
            'meta_description' => isset($import['meta_description'])
                ? (trim((string) $import['meta_description']) !== '' ? trim((string) $import['meta_description']) : null)
                : null,
            'h1_title' => trim((string) ($import['h1_title'] ?? '')),
        ];
    }

    public function contentHash(string $body): string
    {
        return $this->contentConflictGuard->contentHash($body);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{
     *     success: bool,
     *     message: string,
     *     expected_content_hash?: string,
     *     persisted_content_hash?: string,
     *     body_length?: int,
     *     conflict?: bool,
     *     ancillary_status?: string,
     *     ancillary_failures?: list<string>
     * }
     */
    public function publishArticle(SeoArticle $article, string $aiOutput, array $variables = []): array
    {
        $markdown = trim($aiOutput);
        if ($markdown === '') {
            return ['success' => false, 'message' => 'Kết quả AI trống.'];
        }

        try {
            $prepared = $this->prepareArticleContent($article, $markdown);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        // PURE resolution only — no FAQ/keyword/meta/title-protection mutations before body commit.
        $html = $prepared['html'];
        $faqs = $prepared['faqs'];
        $intendedHash = $prepared['content_hash'];
        $h1Title = $prepared['h1_title'];
        $title = $this->resolvePublishTitle($article, $variables, $prepared['markdown'], $h1Title);
        $slug = $this->resolveSlugForPublish($article, $variables, $title);

        $update = [
            'title' => $title,
            'body' => $html,
            'user_id' => auth()->id(),
        ];
        if ($slug !== null) {
            $update['slug'] = $slug;
        }

        $articleId = (int) $article->id;
        $siteId = (int) ($article->site_id ?? 0);
        $baselineHash = $this->contentConflictGuard->contentHash((string) ($article->body ?? ''));
        $expectedUpdatedAt = $article->updated_at?->toIso8601String();
        $correlationId = Str::uuid()->toString();

        $contentInput = [
            'article_id' => $articleId,
            'content' => $html,
            'title' => $title,
            'expected_content_hash' => $baselineHash,
            'expected_updated_at' => $expectedUpdatedAt,
        ];
        if ($slug !== null) {
            $contentInput['slug'] = $slug;
        }

        $articleState = [
            'article_id' => $articleId,
            'status' => (string) ($article->status ?? 'draft'),
            'body' => (string) ($article->body ?? ''),
            'title' => (string) ($article->title ?? ''),
            'updated_at' => $expectedUpdatedAt,
        ];

        try {
            $runBodyWrite = $this->bodyWriteInterceptor
                ?? fn (
                    array $input,
                    array $state,
                    callable $legacyWrite,
                    callable $actionWrite,
                    ?string $correlationId = null,
                ): mixed => $this->contentBridge->run(
                    input: $input,
                    articleState: $state,
                    legacyWrite: $legacyWrite,
                    actionWrite: $actionWrite,
                    correlationId: $correlationId,
                );

            $runBodyWrite(
                $contentInput,
                $articleState,
                function () use ($article, $update, $html, $title, $baselineHash): array {
                    $currentHash = $this->contentConflictGuard->contentHash((string) ($article->body ?? ''));
                    $currentTitle = trim((string) ($article->title ?? ''));
                    $noop = $currentHash === $this->contentConflictGuard->contentHash($html)
                        && trim($title) === $currentTitle
                        && ! array_key_exists('slug', $update);

                    if (! $noop) {
                        $article->update($update);
                    }

                    $fresh = $article->fresh() ?? $article;

                    return [
                        'article_id' => (int) $fresh->id,
                        'status' => (string) ($fresh->status ?? 'draft'),
                        'noop' => $noop,
                        'changed_fields' => $noop ? [] : array_values(array_filter([
                            $currentHash !== $this->contentConflictGuard->contentHash($html) ? 'content' : null,
                            trim($title) !== $currentTitle ? 'title' : null,
                            array_key_exists('slug', $update) ? 'slug' : null,
                        ])),
                        'content_hash' => $this->contentConflictGuard->contentHash((string) ($fresh->body ?? $html)),
                        'updated_at' => $fresh->updated_at?->toIso8601String(),
                        'expected_content_hash' => $baselineHash,
                    ];
                },
                fn (): ActionResult => $this->actionRunner->run(
                    'article.content.update',
                    ActionContext::fromArray([
                        'origin' => 'migration.project_article_content_update',
                        'actor_id' => auth()->id() !== null ? (int) auth()->id() : null,
                        'site_id' => $siteId > 0 ? $siteId : null,
                        'correlation_id' => $correlationId,
                    ]),
                    $contentInput,
                ),
                $correlationId,
            );
        } catch (AutomationMigrationWriteException $exception) {
            $message = $exception->getMessage();
            $isConflict = str_contains(strtolower($message), 'conflict')
                || str_contains($message, 'conflict_content_hash')
                || str_contains($message, 'conflict_updated_at')
                || str_contains(strtolower($message), 'hash mismatch')
                || str_contains(strtolower($message), 'refusing silent overwrite');

            // Guaranteed: ZERO ancillary AI mutations when guarded body write fails/stales.
            return [
                'success' => false,
                'message' => $message,
                'expected_content_hash' => $intendedHash,
                'persisted_content_hash' => $this->contentConflictGuard->contentHash((string) ($article->fresh()?->body ?? $article->body ?? '')),
                'body_length' => strlen((string) ($article->fresh()?->body ?? $article->body ?? '')),
                'conflict' => $isConflict,
                'ancillary_status' => 'skipped',
            ];
        }

        $fresh = $article->fresh() ?? $article;
        $persistedBody = (string) ($fresh->body ?? '');
        $persistedHash = $this->contentConflictGuard->contentHash($persistedBody);
        if ($persistedHash !== $intendedHash) {
            return [
                'success' => false,
                'message' => 'Article body hash mismatch after publish.',
                'expected_content_hash' => $intendedHash,
                'persisted_content_hash' => $persistedHash,
                'body_length' => strlen($persistedBody),
                'ancillary_status' => 'skipped',
            ];
        }

        // Body commit verified — only now commit ancillary AI state.
        $ancillary = $this->commitAncillaryAfterBodyVerified(
            $fresh,
            $prepared['markdown'],
            $faqs,
            $prepared['meta_description'],
            $variables,
            $title,
            $html,
            $baselineHash,
            $persistedHash,
        );

        return [
            'success' => true,
            'message' => sprintf(
                'Đã lưu nội dung bài «%s» vào editor (chỉ Laravel, không đồng bộ WordPress).',
                $title,
            ),
            'expected_content_hash' => $intendedHash,
            'persisted_content_hash' => $persistedHash,
            'body_length' => strlen($persistedBody),
            'ancillary_status' => $ancillary['status'],
            'ancillary_failures' => $ancillary['failures'],
        ];
    }

    /**
     * Post-body ancillary order (explicit):
     * 1 focus keyword
     * 2 FAQ
     * 3 meta description
     * 4 generated title protection
     * 5 media sync from HTML
     * 6 SEO analysis
     * 7 local-edit-pending marker
     * 8 editor readiness (local only — no WP HTTP)
     * 9 AI saved timestamp
     *
     * @param  list<array<string, mixed>>  $faqs
     * @param  array<string, mixed>  $variables
     * @return array{status: string, failures: list<string>}
     */
    private function commitAncillaryAfterBodyVerified(
        SeoArticle $article,
        string $markdown,
        array $faqs,
        ?string $metaDescription,
        array $variables,
        string $title,
        string $html,
        string $baselineHash,
        string $persistedHash,
    ): array {
        $failures = [];

        $failures = array_merge($failures, $this->safeAncillary('focus_keyword', function () use ($article, $variables, $markdown): void {
            $this->syncFocusKeyword($article, $variables, $markdown);
        }));

        if ($faqs !== []) {
            $failures = array_merge($failures, $this->safeAncillary('faq', function () use ($article, $faqs): void {
                app(SeoFaqPersistenceService::class)->persistForArticle($article, $faqs);
            }));
        }

        $failures = array_merge($failures, $this->safeAncillary('meta_description', function () use ($article, $metaDescription): void {
            $this->persistMetaDescription($article, $metaDescription);
        }));

        $failures = array_merge($failures, $this->safeAncillary('title_protection', function () use ($variables, $title): void {
            $this->persistGeneratedTitleProtection($variables, $title);
        }));

        $wroteViaAction = $this->migrationFlags
            ->mode(AutomationMigrationFlags::PROJECT_ARTICLE_CONTENT_UPDATE)
            ->writesViaAction();

        if (! $wroteViaAction) {
            $failures = array_merge($failures, $this->safeAncillary('media_images', function () use ($article, $html): void {
                app(ArticlePostImagesService::class)->syncFromHtml($article->fresh() ?? $article, $html);
            }));
            $failures = array_merge($failures, $this->safeAncillary('seo_analysis', function () use ($article): void {
                app(SeoAnalyzerService::class)->analyze($article->fresh() ?? $article);
            }));
            $failures = array_merge($failures, $this->safeAncillary('local_edit_pending', function () use ($article): void {
                app(ArticleWordPressSyncFlagService::class)->markLocalEditPending($article->fresh() ?? $article);
            }));
        }

        $failures = array_merge($failures, $this->safeAncillary('editor_readiness', function () use ($article): void {
            app(ArticleEditorReadinessService::class)->syncWpPostContentFromBody($article->fresh() ?? $article);
        }));

        if ($persistedHash !== $baselineHash) {
            $failures = array_merge($failures, $this->safeAncillary('ai_saved_timestamp', function () use ($article): void {
                app(ArticleLastSavedTimestampService::class)->touchAiContent($article->fresh() ?? $article);
            }));
        }

        if ($failures !== []) {
            \Illuminate\Support\Facades\Log::warning('writing.trace.ancillary_partial', [
                'article_id' => (int) $article->getKey(),
                'ancillary_status' => 'partial',
                'ancillary_failures' => $failures,
                'expected_content_hash' => $persistedHash,
                'persisted_content_hash' => $persistedHash,
            ]);
        }

        return [
            'status' => $failures === [] ? 'applied' : 'partial',
            'failures' => $failures,
        ];
    }

    /**
     * @param  callable(): void  $callback
     * @return list<string>
     */
    private function safeAncillary(string $name, callable $callback): array
    {
        try {
            $callback();

            return [];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('writing.trace.ancillary_failure', [
                'operation' => $name,
                'message' => $e->getMessage(),
            ]);

            return [$name.': '.$e->getMessage()];
        }
    }

    private function persistMetaDescription(SeoArticle $article, ?string $metaDescription): void
    {
        $metaDescription = trim((string) $metaDescription);
        if ($metaDescription === '') {
            return;
        }

        $articleId = (int) $article->id;
        $siteId = (int) ($article->site_id ?? 0);
        $correlationId = Str::uuid()->toString();

        $currentMeta = '';
        foreach (['seo_meta_description', 'meta_description'] as $key) {
            $value = trim((string) ($article->articleMetas()
                ->where('meta_key', $key)
                ->value('meta_value') ?? ''));
            if ($value !== '') {
                $currentMeta = $value;
                break;
            }
        }

        $input = [
            'article_id' => $articleId,
            'meta_description' => $metaDescription,
            'dispatch_scoring' => false,
        ];

        $metaState = [
            'article_id' => $articleId,
            'status' => (string) ($article->status ?? 'draft'),
            'slug' => (string) ($article->slug ?? ''),
            'focus_keyword' => '',
            'meta_description' => $currentMeta,
            'updated_at' => $article->updated_at?->toIso8601String(),
        ];

        try {
            $this->seoMetaBridge->run(
                input: $input,
                metaState: $metaState,
                legacyWrite: function () use ($article, $metaDescription, $articleId): array {
                    foreach (['seo_meta_description', 'meta_description'] as $key) {
                        $article->articleMetas()->updateOrCreate(
                            ['meta_key' => $key],
                            ['meta_value' => $metaDescription],
                        );
                    }

                    return [
                        'article_id' => $articleId,
                        'meta_description' => $metaDescription,
                        'focus_keyword' => '',
                        'slug' => (string) ($article->slug ?? ''),
                        'seo_analysis_pending' => false,
                        'changed_fields' => ['meta_description'],
                    ];
                },
                actionWrite: fn (): ActionResult => $this->actionRunner->run(
                    'article.seo_meta.update',
                    ActionContext::fromArray([
                        'origin' => 'migration.project_article_seo_meta_update',
                        'actor_id' => auth()->id() !== null ? (int) auth()->id() : null,
                        'site_id' => $siteId > 0 ? $siteId : null,
                        'correlation_id' => $correlationId,
                    ]),
                    $input,
                ),
                correlationId: $correlationId,
            );
        } catch (AutomationMigrationWriteException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
    }

    private function persistOutlineAndKeywords(SeoArticle $article, string $markdown): void
    {
        $outlineJson = $this->outlineParser->parse($markdown);
        $keywordGroups = $this->keywordsParser->parse($markdown);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_article_outline'],
            ['meta_value' => $markdown],
        );

        app(\Omnichannel\Addons\Content\Services\ArticleOutlineResolver::class)
            ->persistStructuredRows($article, $markdown);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_outline_json'],
            [
                'meta_value' => json_encode($outlineJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        );

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_semantic_keywords'],
            [
                'meta_value' => json_encode($keywordGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        );
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function syncFocusKeyword(SeoArticle $article, array $variables, string $markdown): void
    {
        $phrase = trim((string) ($variables['focus_keyword'] ?? ''));
        if ($phrase === '') {
            $phrase = trim((string) ($variables['post_title'] ?? ''));
        }

        if ($phrase === '') {
            return;
        }

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_focus_keyword'],
            ['meta_value' => $phrase],
        );

        KeywordFocusAttach::attachMainKeyword(
            $article,
            (int) $article->site_id,
            $phrase,
        );
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function resolveSlugForPublish(SeoArticle $article, array $variables, string $title): ?string
    {
        if (filled($article->slug)) {
            return null;
        }

        $source = trim((string) ($variables['focus_keyword'] ?? ''));
        if ($source === '') {
            $source = trim((string) ($variables['post_title'] ?? ''));
        }
        if ($source === '') {
            $source = trim($title);
        }

        $slug = Str::slug($source);

        return $slug !== '' ? $slug : null;
    }

    /**
     * Title lifecycle for Content Project items:
     * - user / reviewed / generated (already set) → do not overwrite from AI H1
     * - otherwise first successful AI title may land, then sticky as generated
     *
     * @param  array<string, mixed>  $variables
     */
    private function resolvePublishTitle(
        SeoArticle $article,
        array $variables,
        string $markdown,
        string $h1Title,
    ): string {
        $existingTitle = trim((string) ($article->title ?? ''));
        $protect = (string) ($variables['_protect_article_title'] ?? '') === '1'
            || in_array(
                strtolower(trim((string) ($variables['_item_title_protection'] ?? ''))),
                ['user', 'reviewed', 'generated'],
                true,
            );

        if ($protect) {
            $userTitle = trim((string) ($variables['post_title'] ?? ''));
            $protection = strtolower(trim((string) ($variables['_item_title_protection'] ?? '')));
            if (in_array($protection, ['user', 'reviewed'], true) && $userTitle !== '') {
                return $userTitle;
            }
            if ($existingTitle !== '') {
                return $existingTitle;
            }
        }

        // Pure resolution only — title_protection sticky is persisted after verified body commit.
        return $h1Title !== ''
            ? $h1Title
            : $this->resolveTitle($variables, $markdown, $article);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function persistGeneratedTitleProtection(array $variables, string $title): void
    {
        if ($title === '') {
            return;
        }

        if ((string) ($variables['_protect_article_title'] ?? '') === '1') {
            return;
        }

        $taskId = \Omnichannel\Addons\ContentProjects\Support\ProjectTaskOriginVariables::read($variables);
        if ($taskId === null) {
            return;
        }

        try {
            $task = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()->find($taskId);
            if (! $task instanceof \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask) {
                return;
            }
            if (! \Illuminate\Support\Facades\Schema::connection($task->getConnectionName())
                ->hasColumn($task->getTable(), 'title_protection')) {
                return;
            }
            $current = trim((string) ($task->title_protection ?? ''));
            if ($current !== '') {
                return;
            }
            $task->forceFill([
                'title_protection' => \Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemTitleProtection::Generated->value,
            ])->saveQuietly();
        } catch (\Throwable) {
            // Title protection sticky is best-effort.
        }
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function resolveTitle(array $variables, string $markdown, SeoArticle $article): string
    {
        $fromVar = trim((string) ($variables['post_title'] ?? ''));
        if ($fromVar !== '') {
            return $fromVar;
        }

        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            if (preg_match('/^#\s+(.+)$/u', trim($line), $matches) === 1) {
                return trim($matches[1]);
            }
        }

        $firstH2 = $this->outlineParser->parse($markdown)['sections'][0]['title'] ?? '';

        if ($firstH2 !== '') {
            return $firstH2;
        }

        return (string) ($article->title ?: 'Bài viết mới');
    }
}
