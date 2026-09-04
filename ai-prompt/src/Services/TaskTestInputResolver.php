<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Services\SeoMainDomainService;
use Omnichannel\Addons\Content\Enums\ArticleWritingSourceType;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleWritingAssembler;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFreshKeywordRestart;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectTaskCanonicalInputBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ContentProjectItemGenerationPolicyApplier;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskOriginVariables;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;

final class TaskTestInputResolver
{
    /** @var null|callable(Builder): void */
    private $articleScope = null;

    public function __construct(
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly SeoMainDomainService $mainDomain,
        private readonly SeoPromptSettingsService $promptSettings,
        private readonly WorkflowParserService $workflowParser,
        private readonly WordPressArticleContentService $wordPressContent,
        private readonly ArticleWritingAssembler $articleWritingAssembler,
        private readonly ContentProjectExistingArticleReconciler $existingArticleReconciler = new ContentProjectExistingArticleReconciler,
    ) {}

    /**
     * @param  null|callable(Builder): void  $scopeArticles
     */
    public function resolve(?int $articleId, ?string $title, ?string $keyword, ?callable $scopeArticles = null): TaskTestContext
    {
        $this->articleScope = $scopeArticles;

        try {
            return $this->resolveScoped($articleId, $title, $keyword);
        } finally {
            $this->articleScope = null;
        }
    }

    public function resolveFromRawInput(string $input): TaskTestContext
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('Nhập nội dung {{input}} để chạy thử.');
        }

        $preview = mb_strlen($input) > 48 ? mb_substr($input, 0, 48).'…' : $input;

        return new TaskTestContext(
            article: null,
            isNewArticle: false,
            matchedBy: null,
            variables: [
                'input' => $input,
                'user_brief' => $input,
                'brief_free_input' => $input,
                'article_writing_source_type' => ArticleWritingSourceType::Brief->value,
                'source_type' => ArticleWritingSourceType::Brief->value,
            ],
            summary: sprintf('Input test — «%s»', $preview),
        );
    }

    /**
     * @param  null|callable(Builder): void  $scopeArticles
     */
    public function resolveForProjectTask(
        SeoProjectTask $task,
        ?callable $scopeArticles = null,
        bool $cleanRestart = false,
    ): TaskTestContext {
        $type = SeoProjectTask::normalizeType($task->type);
        $effectiveKeyword = ContentProjectGenerationKeyword::effective($task);
        $promptInputs = SeoProjectTask::promptInputFields(
            $effectiveKeyword !== '' ? $effectiveKeyword : (isset($task->keyword) ? (string) $task->keyword : null),
            isset($task->title) ? (string) $task->title : null,
            isset($task->secondary_description) ? (string) $task->secondary_description : null,
        );
        $keyword = $promptInputs['keyword'];
        $title = $promptInputs['title'];
        $secondaryDescription = $promptInputs['secondary_description'];

        // Legacy fallback: single source_content trước khi tách keyword/title.
        if ($keyword === '' && $title === '' && SeoProjectTask::isNewArticleType($type)) {
            $legacy = trim((string) $task->source_content);
            $keyword = $legacy;
        }

        if ($type === SeoProjectTask::TYPE_CREATE) {
            ContentProjectItemIdentity::assertValid($keyword, $title);
        }

        $galleryDescription = SeoProjectTask::isNewArticleType($type)
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            ? trim((string) ($task->description ?? ''))
            : '';
        $loaiSanPham = SeoProjectTask::isNewArticleType($type)
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            ? trim((string) ($task->loai_san_pham ?? ''))
            : '';

        if ($type === SeoProjectTask::TYPE_REWRITE || $type === SeoProjectTask::TYPE_IMPROVE) {
            $rewriteContext = $cleanRestart && $type === SeoProjectTask::TYPE_REWRITE
                ? $this->resolveExistingArticleRewriteForCleanRestart($task, $scopeArticles)
                : $this->resolveExistingArticleRewrite($task, $scopeArticles, $type);

            return $this->stampProjectTaskOrigin(
                $this->withOptionalPromptInputs(
                    $this->withProductPromptVariables(
                        $rewriteContext,
                        $galleryDescription,
                        $loaiSanPham,
                    ),
                    $keyword,
                    $title,
                    $secondaryDescription,
                ),
                $task,
            );
        }

        $siteId = (int) ($task->site_id ?? 0);
        $postType = SeoProjectTask::normalizePostType($task->post_type);

        if ($cleanRestart) {
            return $this->stampProjectTaskOrigin(
                $this->resolveCreateArticleForCleanRestart(
                    $task,
                    $scopeArticles,
                    $keyword,
                    $title,
                    $secondaryDescription,
                    $galleryDescription,
                    $loaiSanPham,
                    $siteId,
                    $postType,
                ),
                $task,
            );
        }

        return $this->stampProjectTaskOrigin(
            $this->withOptionalPromptInputs(
                $this->withProductPromptVariables(
                    $this->applyProjectPostType(
                        $this->contextForNewArticleOnSite(
                            $title,
                            $keyword,
                            $siteId,
                            $postType,
                            $scopeArticles,
                            copyMissingTitleKeyword: false,
                        )->withProjectTaskType(SeoProjectTask::TYPE_CREATE),
                        $postType,
                    ),
                    $galleryDescription,
                    $loaiSanPham,
                ),
                $keyword,
                $title,
                $secondaryDescription,
            ),
            $task,
        );
    }

    /**
     * Fresh keyword restart — keyword override only; no inherited outline/content/rewrite source.
     *
     * @param  null|callable(Builder): void  $scopeArticles
     */
    public function resolveFreshKeywordRestartForProjectTask(
        SeoProjectTask $task,
        string $keywordOverride,
        ?callable $scopeArticles = null,
    ): TaskTestContext {
        $keyword = ContentProjectItemIdentity::normalize(trim($keywordOverride));
        if ($keyword === '') {
            throw new \InvalidArgumentException('Từ khóa tạo bài mới là bắt buộc.');
        }

        $type = SeoProjectTask::normalizeType($task->type);
        $siteId = (int) ($task->site_id ?? 0);

        $this->articleScope = $scopeArticles;

        try {
            $articleId = (int) ($task->article_id ?? 0);
            $article = $articleId > 0 ? $this->articlesQuery()->find($articleId) : null;

            if ($article instanceof SeoArticle) {
                $article->loadMissing(['articleMetas', 'site']);
            }

            $postType = $this->resolveCanonicalPostType($task, $article);
            $galleryDescription = SeoProjectTask::isNewArticleType($type)
                && $postType === SeoProjectTask::POST_TYPE_PRODUCT
                ? trim((string) ($task->description ?? ''))
                : '';
            $loaiSanPham = SeoProjectTask::isNewArticleType($type)
                && $postType === SeoProjectTask::POST_TYPE_PRODUCT
                ? trim((string) ($task->loai_san_pham ?? ''))
                : '';

            $variables = $this->baseVariables('', $keyword, $articleId > 0 ? $articleId : null);
            $site = $siteId > 0 ? Site::query()->find($siteId) : $this->mainDomain->resolveMainSite();
            $promptVars = $this->promptSettings->promptVariables($postType);
            $variables = array_merge(
                $variables,
                $promptVars,
                $this->sitePromptContext->promptVariablesForSite($site),
            );
            $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
                $site,
                $promptVars['tone'] ?? '',
            );
            $variables['post_type'] = $postType;
            $variables = ContentProjectFreshKeywordRestart::stampVariables($variables, $keyword);

            $context = new TaskTestContext(
                article: null,
                isNewArticle: $article === null,
                matchedBy: null,
                variables: $variables,
                summary: sprintf('Fresh keyword restart — «%s»', $keyword),
                siteId: $siteId > 0 ? $siteId : null,
                postType: $postType,
                projectTaskType: $type,
            );

            $context = $this->applyProjectPostType($context, $postType);

            if ($article instanceof SeoArticle) {
                $context = $context
                    ->withProjectTaskType($type)
                    ->withArticle($article, false, 'id');
                if ($context->siteId === null && $siteId > 0) {
                    $context = $context->withSiteId($siteId);
                }
            }

            return $this->stampProjectTaskOrigin(
                $this->withProductPromptVariables($context, $galleryDescription, $loaiSanPham),
                $task,
            );
        } finally {
            $this->articleScope = null;
        }
    }

    private function withOptionalPromptInputs(
        TaskTestContext $context,
        string $keyword,
        string $title,
        string $secondaryDescription,
    ): TaskTestContext {
        $variables = $context->variables;
        $keywordNorm = ContentProjectItemIdentity::normalize($keyword);
        $explicitTitle = ContentProjectItemIdentity::normalize($title);

        if ($keywordNorm !== '') {
            $variables['focus_keyword'] = $keywordNorm;
            $variables['keyword'] = $keywordNorm;
        } else {
            unset($variables['focus_keyword'], $variables['keyword']);
        }

        if ($explicitTitle !== '') {
            $variables['post_title'] = $explicitTitle;
            $variables['title'] = $explicitTitle;
        } elseif ($context->isNewArticle) {
            // Create + empty title: generation seed only (effectiveSubject).
            // Does not mutate SeoProjectTask.title. Final Article.title still prefers AI H1
            // via PromptTestPublishService (h1_title before post_title fallback).
            $seed = ContentProjectItemIdentity::effectiveSubject(null, $keywordNorm);
            if ($seed !== '') {
                $variables['post_title'] = $seed;
                $variables['title'] = $seed;
            } else {
                unset($variables['post_title'], $variables['title']);
            }
        } else {
            // Rewrite/improve: keep existing article title — never replace with keyword fallback.
            $existingTitle = ContentProjectItemIdentity::normalize(
                isset($variables['post_title']) ? (string) $variables['post_title'] : null,
            );
            if ($existingTitle !== '') {
                $variables['post_title'] = $existingTitle;
                $variables['title'] = $existingTitle;
            }
        }

        $resolvedTitle = ContentProjectItemIdentity::normalize(
            isset($variables['post_title']) ? (string) $variables['post_title'] : null,
        );
        $topic = ContentProjectItemIdentity::effectiveSubject($resolvedTitle, $keywordNorm);
        if ($topic !== '') {
            $variables['topic'] = $topic;
        } else {
            unset($variables['topic']);
        }

        if ($secondaryDescription !== '') {
            $variables['secondary_description'] = $secondaryDescription;
            $variables['description'] = $secondaryDescription;
        }

        return $context->withVariables($variables);
    }

    /**
     * CREATE «Run again» / full rerun: keep the linked article row, force AI from task keyword.
     * Must not resolve-by-title (stale generated title) or reuse outline/body.
     *
     * @param  null|callable(\Illuminate\Database\Eloquent\Builder): void  $scopeArticles
     */
    private function resolveCreateArticleForCleanRestart(
        SeoProjectTask $task,
        ?callable $scopeArticles,
        string $keyword,
        string $title,
        string $secondaryDescription,
        string $galleryDescription,
        string $loaiSanPham,
        int $siteId,
        string $postType,
    ): TaskTestContext {
        $this->articleScope = $scopeArticles;

        try {
            $articleId = (int) ($task->article_id ?? 0);
            $article = $articleId > 0 ? $this->articlesQuery()->find($articleId) : null;

            if ($article instanceof SeoArticle) {
                $article->loadMissing(['articleMetas', 'site']);
                $context = $this->contextFromArticle($article, 'id')
                    ->withProjectTaskType(SeoProjectTask::TYPE_CREATE)
                    ->withArticle($article, true, 'id');
            } else {
                $context = $this->applyProjectPostType(
                    $this->contextForNewArticleOnSite(
                        $title,
                        $keyword,
                        $siteId,
                        $postType,
                        $scopeArticles,
                        copyMissingTitleKeyword: false,
                    )->withProjectTaskType(SeoProjectTask::TYPE_CREATE),
                    $postType,
                );
            }

            $variables = $context->variables;
            $variables['rerun_scope'] = 'full';
            $variables['force_ai_regenerate'] = 'true';
            unset(
                $variables['article_writing_raw_input'],
                $variables['article_writing_formatted'],
                $variables['article_generation_source'],
                $variables['outline_id'],
                $variables['outline_version'],
                $variables['outline_source'],
                $variables['outline_marker_found'],
                $variables['writing_instructions_marker_found'],
                $variables['artifact_version'],
                $variables['artifact_hash'],
            );

            $taskSiteId = (int) ($task->site_id ?? 0);
            if ($context->siteId === null && $taskSiteId > 0) {
                $context = $context->withSiteId($taskSiteId);
            }

            $context = $this->withOptionalPromptInputs(
                $this->withProductPromptVariables(
                    $this->applyProjectPostType(
                        $context->withVariables($variables),
                        $postType,
                    ),
                    $galleryDescription,
                    $loaiSanPham,
                ),
                $keyword,
                $title,
                $secondaryDescription,
            );

            $variables = $context->variables;
            $keywordNorm = ContentProjectItemIdentity::normalize($keyword);
            if ($keywordNorm !== '') {
                $variables['focus_keyword'] = $keywordNorm;
                $variables['keyword'] = $keywordNorm;
                $variables['topic'] = $keywordNorm;
                $explicitTitle = ContentProjectItemIdentity::normalize($title);
                $articleTitle = ContentProjectItemIdentity::normalize(
                    $context->article?->title !== null ? (string) $context->article->title : null,
                );
                if ($explicitTitle === '' || ($articleTitle !== '' && $explicitTitle === $articleTitle)) {
                    $variables['post_title'] = $keywordNorm;
                    $variables['title'] = $keywordNorm;
                }
            }

            return $context->withVariables($variables);
        } finally {
            $this->articleScope = null;
        }
    }

    /**
     * @param  null|callable(\Illuminate\Database\Eloquent\Builder): void  $scopeArticles
     */
    private function resolveExistingArticleRewrite(
        SeoProjectTask $task,
        ?callable $scopeArticles,
        string $type,
    ): TaskTestContext {
        $this->articleScope = $scopeArticles;

        try {
            $article = $this->resolveExistingArticleForTask($task);

            if (! $article instanceof SeoArticle) {
                $label = $type === SeoProjectTask::TYPE_IMPROVE ? 'cải thiện' : 'viết lại';
                throw new \InvalidArgumentException(
                    'Không tìm thấy bài viết để '.$label.' (task #'.(int) $task->id.'). '
                    .'Hãy chọn đúng Target / Existing Article.',
                );
            }

            $article->loadMissing(['articleMetas', 'site']);
            $notes = trim((string) ($task->rewrite_notes ?? ''));
            $context = $this->contextFromArticle($article, 'id')
                ->withProjectTaskType($type)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);

            $variables = $context->variables;

            if ($type === SeoProjectTask::TYPE_IMPROVE) {
                // Improve: body + instruction — KHÔNG stamp article writing source / generate.
                $html = trim($this->wordPressContent->resolveEditorHtml($article));
                $markdown = $this->workflowParser->convertHtmlFragmentToMarkdown($html);
                if ($markdown === '') {
                    throw new \InvalidArgumentException(
                        $html === ''
                            ? 'Bài viết không có nội dung HTML để chuyển sang Markdown (kiểm tra body local hoặc đồng bộ từ WordPress).'
                            : 'Bài viết không có nội dung Markdown sau khi chuyển đổi (có thể chỉ còn shortcode hoặc khối không có chữ).',
                    );
                }
                $variables['input'] = $markdown;
                $variables['post_content'] = $markdown;
                $variables['improve_instruction'] = $notes;
                $variables['rewrite_instruction'] = $notes;
                $variables['rewrite_notes'] = $notes;
                $variables['article_improve_capability'] = ArticleImproveExecutionService::HOOK_KEY;
                unset(
                    $variables['article_writing_source_type'],
                    $variables['source_type'],
                    $variables['article_writing_formatted'],
                );
            } else {
                // TYPE_REWRITE / «Tạo lại bài từ dàn ý»: outline → article.content.generate.
                $variables = $this->articleWritingAssembler->applyOutlineFromArticle($article, $variables);
                $variables['rewrite_instruction'] = $notes;
                $variables['rewrite_notes'] = $notes;
            }

            $taskSiteId = (int) ($task->site_id ?? 0);
            if ($context->siteId === null && $taskSiteId > 0) {
                $context = $context->withSiteId($taskSiteId);
            }

            return $context
                ->withVariables($variables)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);
        } finally {
            $this->articleScope = null;
        }
    }

    private function resolveExistingArticleRewriteForCleanRestart(
        SeoProjectTask $task,
        ?callable $scopeArticles,
    ): TaskTestContext {
        $this->articleScope = $scopeArticles;

        try {
            $article = $this->resolveExistingArticleForTask($task);

            if (! $article instanceof SeoArticle) {
                throw new \InvalidArgumentException(
                    'Không tìm thấy bài viết để viết lại (task #'.(int) $task->id.'). '
                    .'Hãy chọn đúng Target / Existing Article.',
                );
            }

            $article->loadMissing(['articleMetas', 'site']);
            $notes = trim((string) ($task->rewrite_notes ?? ''));
            $context = $this->contextFromArticle($article, 'id')
                ->withProjectTaskType(SeoProjectTask::TYPE_REWRITE)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);

            $variables = $context->variables;
            $variables['rewrite_instruction'] = $notes;
            $variables['rewrite_notes'] = $notes;
            $variables['rerun_scope'] = 'full';
            $variables['force_ai_regenerate'] = 'true';
            unset(
                $variables['article_writing_raw_input'],
                $variables['article_writing_formatted'],
                $variables['article_generation_source'],
                $variables['outline_id'],
                $variables['outline_version'],
                $variables['outline_source'],
                $variables['outline_marker_found'],
                $variables['writing_instructions_marker_found'],
                $variables['artifact_version'],
                $variables['artifact_hash'],
            );

            $taskSiteId = (int) ($task->site_id ?? 0);
            if ($context->siteId === null && $taskSiteId > 0) {
                $context = $context->withSiteId($taskSiteId);
            }

            return $context
                ->withVariables($variables)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);
        } finally {
            $this->articleScope = null;
        }
    }

    /**
     * Prefer canonical reconciler (persist) then exact article_id — never fuzzy title LIKE.
     */
    private function resolveExistingArticleForTask(SeoProjectTask $task): ?SeoArticle
    {
        try {
            $repaired = $this->existingArticleReconciler->reconcileTask($task, persist: true);
            if ($repaired->isUsable() && $repaired->articleId !== null) {
                $task->refresh();
                $article = $this->articlesQuery()->find((int) $repaired->articleId);
                if ($article instanceof SeoArticle) {
                    return $article;
                }
            }
        } catch (\Throwable) {
            // Fall through to direct article_id.
        }

        $articleId = (int) ($task->article_id ?? 0);
        if ($articleId > 0) {
            $article = $this->articlesQuery()->find($articleId);
            if ($article instanceof SeoArticle) {
                return $article;
            }
        }

        return null;
    }

    private function stampProjectTaskOrigin(TaskTestContext $context, SeoProjectTask $task): TaskTestContext
    {
        $stamped = $context->withVariables(
            ProjectTaskOriginVariables::stamp(
                $context->variables,
                (int) $task->id,
            ),
        );

        $withPolicy = app(ContentProjectItemGenerationPolicyApplier::class)->apply($stamped, $task);

        $type = SeoProjectTask::normalizeType((string) ($task->type ?? $withPolicy->projectTaskType ?? ''));
        $variables = ContentProjectTaskCanonicalInputBuilder::stamp(
            $withPolicy->variables,
            $type,
            $task,
        );

        return $withPolicy->withVariables($variables);
    }

    /**
     * Resolve canonical post_type: prefer task.post_type, fallback to article's resolved type.
     * Ensures fresh_keyword_restart inherits post_type even when task row has no explicit value.
     */
    private function resolveCanonicalPostType(SeoProjectTask $task, ?SeoArticle $article): string
    {
        $taskPostType = trim((string) ($task->post_type ?? ''));

        if ($taskPostType !== '' && in_array($taskPostType, SeoProjectTask::postTypeKeys(), true)) {
            return $taskPostType;
        }

        if ($article instanceof SeoArticle) {
            return ArticlePostTypeResolver::resolve($article);
        }

        return SeoProjectTask::normalizePostType($taskPostType);
    }

    /**
     * Task.post_type là nguồn sự thật cho hạng mục viết bài mới.
     * Không để bài match nhầm (stale product/article) ghi đè context.
     */
    private function applyProjectPostType(TaskTestContext $context, string $postType): TaskTestContext
    {
        $normalized = SeoProjectTask::normalizePostType($postType);
        $variables = $context->variables;
        $promptVars = $this->promptSettings->promptVariables($normalized);
        $variables = array_merge($variables, $promptVars);
        $variables['_project_post_type'] = $normalized;

        $site = null;
        if ($context->article?->relationLoaded('site')) {
            $site = $context->article->site;
        } elseif ($context->siteId !== null && $context->siteId > 0) {
            $site = Site::query()->find($context->siteId);
        }

        $variables = array_merge(
            $variables,
            $this->sitePromptContext->promptVariablesForSite($site instanceof Site ? $site : null),
        );
        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $site instanceof Site ? $site : null,
            $promptVars['tone'] ?? ($variables['tone'] ?? ''),
        );

        return $context
            ->withVariables($variables)
            ->withPostType($normalized);
    }

    private function withProductPromptVariables(
        TaskTestContext $context,
        string $galleryDescription,
        string $loaiSanPham,
    ): TaskTestContext {
        $variables = $context->variables;
        $variables['gallery_description'] = $galleryDescription;
        $variables['loai_san_pham'] = $loaiSanPham;
        $variables['LOAI_SAN_PHAM'] = $loaiSanPham;

        return new TaskTestContext(
            article: $context->article,
            isNewArticle: $context->isNewArticle,
            matchedBy: $context->matchedBy,
            variables: $variables,
            summary: $context->summary,
            siteId: $context->siteId,
            postType: $context->postType,
            projectTaskType: $context->projectTaskType,
            rewriteMode: $context->rewriteMode,
            rewriteNotes: $context->rewriteNotes,
        );
    }

    private function resolveRewriteByContent(SeoProjectTask $task, ?callable $scopeArticles): TaskTestContext
    {
        $this->articleScope = $scopeArticles;

        try {
            $article = $this->resolveExistingArticleForTask($task);

            if (! $article instanceof SeoArticle) {
                throw new \InvalidArgumentException('Không tìm thấy bài viết để viết lại theo nội dung.');
            }

            $article->loadMissing(['articleMetas', 'site']);
            $notes = trim((string) ($task->rewrite_notes ?? ''));
            $context = $this->contextFromArticle($article, 'id')
                ->withProjectTaskType(SeoProjectTask::TYPE_REWRITE)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);

            // Legacy helper — đồng bộ CP outline path (không dùng article body).
            $variables = $this->articleWritingAssembler->applyOutlineFromArticle($article, $context->variables);
            $variables['rewrite_instruction'] = $notes;
            $variables['rewrite_notes'] = $notes;

            $taskSiteId = (int) ($task->site_id ?? 0);
            if ($context->siteId === null && $taskSiteId > 0) {
                $context = $context->withSiteId($taskSiteId);
            }

            return $context
                ->withVariables($variables)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);
        } finally {
            $this->articleScope = null;
        }
    }

    private function resolveScoped(?int $articleId, ?string $title, ?string $keyword): TaskTestContext
    {
        $title = $this->normalize($title);
        $keyword = $this->normalize($keyword);

        if ($articleId !== null && $articleId > 0) {
            $article = $this->articlesQuery()->find($articleId);
            if ($article === null) {
                throw new \InvalidArgumentException('Không tìm thấy bài viết với ID #'.$articleId.' trong danh sách của bạn.');
            }

            return $this->contextFromArticle($article, 'id');
        }

        if ($title !== '') {
            $byTitle = $this->findArticleByTitle($title);
            if ($byTitle !== null) {
                return $this->contextFromArticle($byTitle, 'title');
            }
        }

        if ($keyword !== '') {
            $byKeyword = $this->findArticleByKeyword($keyword);
            if ($byKeyword !== null) {
                return $this->contextFromArticle($byKeyword, 'keyword');
            }
        }

        return $this->contextForNewArticle($title, $keyword);
    }

    private function articlesQuery(): Builder
    {
        $query = SeoArticle::query();
        if ($this->articleScope !== null) {
            ($this->articleScope)($query);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @deprecated Use ArticleWritingAssembler::applyOutlineFromArticle
     */
    private function applyArticleGenerationSource(array $variables, SeoArticle $article): array
    {
        return $this->articleWritingAssembler->applyOutlineFromArticle($article, $variables);
    }

    /**
     * Editor: Viết lại toàn bộ bài hiện có → article.content.generate (existing_article).
     *
     * @param  null|callable(Builder): void  $scopeArticles
     */
    public function resolveEditorFullRewrite(
        SeoArticle $article,
        ?string $notes = null,
        ?callable $scopeArticles = null,
    ): TaskTestContext {
        $this->articleScope = $scopeArticles;

        try {
            $article->loadMissing(['articleMetas', 'site']);
            $notes = trim((string) $notes);
            $context = $this->contextFromArticle($article, 'id')
                ->withProjectTaskType(SeoProjectTask::TYPE_REWRITE)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);

            $variables = $this->articleWritingAssembler->applyExistingArticleFromArticle(
                $article,
                $context->variables,
            );
            if ($notes !== '') {
                $variables['rewrite_instruction'] = $notes;
                $variables['rewrite_notes'] = $notes;
            }

            return $context
                ->withVariables($variables)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);
        } finally {
            $this->articleScope = null;
        }
    }

    private function contextFromArticle(SeoArticle $article, string $matchedBy): TaskTestContext
    {
        $article->loadMissing(['articleMetas']);

        $focusKeyword = $this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '';
        $postTitle = trim((string) ($article->title ?? ''));

        $variables = $this->baseVariables($postTitle, $focusKeyword, (int) $article->id);
        $article->loadMissing('site');
        $postType = ArticlePostTypeResolver::resolve($article);
        $promptVars = $this->promptSettings->promptVariables($postType);
        $variables = array_merge(
            $variables,
            $promptVars,
            $this->sitePromptContext->promptVariablesForSite($article->site),
        );
        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $article->site,
            $promptVars['tone'] ?? '',
        );

        return new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: $matchedBy,
            variables: $variables,
            summary: sprintf(
                'Bài có sẵn #%d — khớp theo %s: «%s»',
                $article->id,
                $matchedBy === 'id' ? 'ID' : ($matchedBy === 'title' ? 'tiêu đề' : 'từ khóa'),
                $postTitle !== '' ? $postTitle : ($focusKeyword !== '' ? $focusKeyword : '—'),
            ),
            siteId: (int) ($article->site_id ?? 0) > 0 ? (int) $article->site_id : null,
            postType: $postType,
        );
    }

    private function contextForNewArticle(string $title, string $keyword): TaskTestContext
    {
        $mainSite = $this->mainDomain->resolveMainSite();
        $siteId = $mainSite instanceof Site ? (int) $mainSite->id : 0;

        return $this->contextForNewArticleOnSite($title, $keyword, $siteId, 'article', $this->articleScope);
    }

    /**
     * @param  null|callable(Builder): void  $scopeArticles
     */
    private function contextForNewArticleOnSite(
        string $title,
        string $keyword,
        int $siteId,
        string $postType,
        ?callable $scopeArticles = null,
        bool $copyMissingTitleKeyword = true,
    ): TaskTestContext {
        $previousScope = $this->articleScope;
        $this->articleScope = $scopeArticles;

        try {
            $postTitle = $title;
            $focusKeyword = $keyword;

            if ($copyMissingTitleKeyword) {
                if ($postTitle === '' && $focusKeyword !== '') {
                    $postTitle = $focusKeyword;
                }

                if ($focusKeyword === '' && $postTitle !== '') {
                    $focusKeyword = $postTitle;
                }
            }

            $normalizedPostType = SeoProjectTask::normalizePostType($postType);

            if ($postTitle !== '') {
                $byTitle = $this->findArticleByTitle($postTitle);
                if (
                    $byTitle instanceof SeoArticle
                    && $this->articleMatchesPostType($byTitle, $normalizedPostType)
                ) {
                    return $this->contextFromArticle($byTitle, 'title');
                }
            }

            if ($focusKeyword !== '') {
                $byKeyword = $this->findArticleByKeyword($focusKeyword);
                if (
                    $byKeyword instanceof SeoArticle
                    && $this->articleMatchesPostType($byKeyword, $normalizedPostType)
                ) {
                    return $this->contextFromArticle($byKeyword, 'keyword');
                }
            }

            $variables = $this->baseVariables($postTitle, $focusKeyword, null);
            $site = $siteId > 0 ? Site::query()->find($siteId) : $this->mainDomain->resolveMainSite();
            $promptVars = $this->promptSettings->promptVariables($normalizedPostType);
            $variables = array_merge(
                $variables,
                $promptVars,
                $this->sitePromptContext->promptVariablesForSite($site instanceof Site ? $site : null),
            );
            $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
                $site instanceof Site ? $site : null,
                $promptVars['tone'] ?? '',
            );
            $variables['_project_post_type'] = $normalizedPostType;

            $label = $postTitle !== '' ? $postTitle : $focusKeyword;
            $summary = sprintf(
                'Tạo bài mới — «%s» (loại %s, site #%s).',
                $label,
                $normalizedPostType,
                $siteId > 0 ? (string) $siteId : '—',
            );

            return new TaskTestContext(
                article: null,
                isNewArticle: true,
                matchedBy: null,
                variables: $variables,
                summary: $summary,
                siteId: $siteId > 0 ? $siteId : null,
                postType: $normalizedPostType,
            );
        } finally {
            $this->articleScope = $previousScope;
        }
    }

    /**
     * @return array<string, string>
     */
    private function baseVariables(string $postTitle, string $focusKeyword, ?int $articleId): array
    {
        $variables = [
            'post_title' => $postTitle,
            'focus_keyword' => $focusKeyword,
            // Align with product-review target default + comment prompt {{comment_count}}.
            'comment_count' => '3',
        ];

        if ($articleId !== null) {
            $variables['article_id'] = (string) $articleId;
        }

        return $variables;
    }

    private function articleMatchesPostType(SeoArticle $article, string $postType): bool
    {
        return ArticlePostTypeResolver::resolve($article) === SeoProjectTask::normalizePostType($postType);
    }

    private function findArticleByTitle(string $title): ?SeoArticle
    {
        $exact = $this->articlesQuery()
            ->where('title', $title)
            ->orderByDesc('id')
            ->first();

        if ($exact instanceof SeoArticle) {
            return $exact;
        }

        return $this->articlesQuery()
            ->where('title', 'like', '%'.$this->escapeLike($title).'%')
            ->orderByDesc('id')
            ->first();
    }

    private function findArticleByKeyword(string $keyword): ?SeoArticle
    {
        $normalized = mb_strtolower($keyword);

        return $this->articlesQuery()
            ->whereHas('articleMetas', function (Builder $query) use ($normalized, $keyword): void {
                $query->where('meta_key', 'seo_focus_keyword')
                    ->where(function (Builder $inner) use ($normalized, $keyword): void {
                        $inner->whereRaw('LOWER(meta_value) = ?', [$normalized])
                            ->orWhere('meta_value', 'like', '%'.$this->escapeLike($keyword).'%');
                    });
            })
            ->orderByDesc('id')
            ->first();
    }

    private function normalize(?string $value): string
    {
        return trim((string) $value);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
