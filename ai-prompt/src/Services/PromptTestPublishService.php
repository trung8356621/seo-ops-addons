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

final class PromptTestPublishService
{
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
     * @param  array<string, string>  $variables
     * @return array{success: bool, message: string}
     */
    public function publishArticle(SeoArticle $article, string $aiOutput, array $variables = []): array
    {
        $markdown = trim($aiOutput);
        if ($markdown === '') {
            return ['success' => false, 'message' => 'Kết quả AI trống.'];
        }

        $this->syncFocusKeyword($article, $variables, $markdown);

        $import = app(ArticleContentFaqService::class)->convertMarkdownImport($markdown);

        $cta = app(ArticleCtaPlaceholderService::class)->applyForPublish(
            (int) $article->site_id > 0 ? (int) $article->site_id : null,
            $import['html'],
            $import['faqs'],
        );
        // Shared safety net for Generate / Rewrite / Improve / Rerun (all use publishArticle).
        $html = app(AiGeneratedContentNormalizer::class)->normalizeHtml($cta['html']);
        $faqs = $cta['faqs'];

        if ($faqs !== []) {
            app(SeoFaqPersistenceService::class)->persistForArticle($article, $faqs);
        }

        $h1Title = trim((string) ($import['h1_title'] ?? ''));
        $title = $h1Title !== ''
            ? $h1Title
            : $this->resolveTitle($variables, $markdown, $article);

        $this->persistMetaDescription($article, $import['meta_description']);

        $update = [
            'title' => $title,
            'body' => $html,
            'user_id' => auth()->id(),
        ];

        $slug = $this->resolveSlugForPublish($article, $variables, $title);
        if ($slug !== null) {
            $update['slug'] = $slug;
        }

        $articleId = (int) $article->id;
        $siteId = (int) ($article->site_id ?? 0);
        $expectedHash = $this->contentConflictGuard->contentHash((string) ($article->body ?? ''));
        $expectedUpdatedAt = $article->updated_at?->toIso8601String();
        $correlationId = Str::uuid()->toString();

        $contentInput = [
            'article_id' => $articleId,
            'content' => $html,
            'title' => $title,
            'expected_content_hash' => $expectedHash,
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
            $this->contentBridge->run(
                input: $contentInput,
                articleState: $articleState,
                legacyWrite: function () use ($article, $update, $html, $title, $expectedHash): array {
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
                        'expected_content_hash' => $expectedHash,
                    ];
                },
                actionWrite: fn (): ActionResult => $this->actionRunner->run(
                    'article.content.update',
                    ActionContext::fromArray([
                        'origin' => 'migration.project_article_content_update',
                        'actor_id' => auth()->id() !== null ? (int) auth()->id() : null,
                        'site_id' => $siteId > 0 ? $siteId : null,
                        'correlation_id' => $correlationId,
                    ]),
                    $contentInput,
                ),
                correlationId: $correlationId,
            );
        } catch (AutomationMigrationWriteException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $wroteViaAction = $this->migrationFlags
            ->mode(AutomationMigrationFlags::PROJECT_ARTICLE_CONTENT_UPDATE)
            ->writesViaAction();

        if (! $wroteViaAction) {
            app(ArticlePostImagesService::class)->syncFromHtml($article->fresh(), $html);
            app(SeoAnalyzerService::class)->analyze($article->fresh());
            app(ArticleWordPressSyncFlagService::class)->markLocalEditPending($article->fresh());
        }

        app(ArticleEditorReadinessService::class)->syncWpPostContentFromBody($article->fresh());

        $fresh = $article->fresh() ?? $article;
        $newHash = $this->contentConflictGuard->contentHash((string) ($fresh->body ?? ''));
        if ($newHash !== $expectedHash) {
            app(ArticleLastSavedTimestampService::class)->touchAiContent($fresh);
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Đã lưu nội dung bài «%s» vào editor (chỉ Laravel, không đồng bộ WordPress).',
                $title,
            ),
        ];
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
