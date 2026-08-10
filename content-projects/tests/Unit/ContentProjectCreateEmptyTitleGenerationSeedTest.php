<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Create empty-title generation seed + outline compile alias mirrors.
 * Asserts VARIABLES / mapped input / compile mirrors â€” not AI output.
 */
final class ContentProjectCreateEmptyTitleGenerationSeedTest extends TestCase
{
    public function test_create_null_title_seeds_generation_variables(): void
    {
        $vars = $this->applyOptionalInputs(
            isNewArticle: true,
            existing: [],
            keyword: 'thá»i trang bá»n vá»¯ng',
            title: '',
        );

        self::assertSame('thá»i trang bá»n vá»¯ng', $vars['keyword'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $vars['focus_keyword'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $vars['topic'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $vars['post_title'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $vars['title'] ?? null);
    }

    public function test_create_empty_string_title_seeds_from_keyword(): void
    {
        $vars = $this->applyOptionalInputs(true, [], 'balo laptop', '');

        self::assertSame('balo laptop', $vars['post_title'] ?? null);
        self::assertSame('balo laptop', $vars['topic'] ?? null);
    }

    public function test_create_whitespace_title_seeds_from_keyword(): void
    {
        $vars = $this->applyOptionalInputs(true, [], 'thá»i trang bá»n vá»¯ng', "  \t  ");

        self::assertSame('thá»i trang bá»n vá»¯ng', $vars['post_title'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $vars['title'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $vars['focus_keyword'] ?? null);
    }

    public function test_create_explicit_title_wins_over_keyword(): void
    {
        $vars = $this->applyOptionalInputs(true, [], 'focus kw', 'TiÃªu Ä‘á» riÃªng');

        self::assertSame('TiÃªu Ä‘á» riÃªng', $vars['post_title'] ?? null);
        self::assertSame('TiÃªu Ä‘á» riÃªng', $vars['title'] ?? null);
        self::assertSame('TiÃªu Ä‘á» riÃªng', $vars['topic'] ?? null);
        self::assertSame('focus kw', $vars['keyword'] ?? null);
        self::assertSame('focus kw', $vars['focus_keyword'] ?? null);
    }

    public function test_rewrite_keeps_article_title_over_keyword_fallback(): void
    {
        $vars = $this->applyOptionalInputs(
            isNewArticle: false,
            existing: [
                'post_title' => 'XÆ°á»Ÿng May Balo Xuáº¥t Kháº©u Uy TÃ­n',
                'title' => 'XÆ°á»Ÿng May Balo Xuáº¥t Kháº©u Uy TÃ­n',
                'focus_keyword' => 'stale',
            ],
            keyword: 'XÆ°á»Ÿng May Balo Xuáº¥t Kháº©u',
            title: '',
        );

        self::assertSame('XÆ°á»Ÿng May Balo Xuáº¥t Kháº©u Uy TÃ­n', $vars['post_title'] ?? null);
        self::assertSame('XÆ°á»Ÿng May Balo Xuáº¥t Kháº©u Uy TÃ­n', $vars['title'] ?? null);
        self::assertSame('XÆ°á»Ÿng May Balo Xuáº¥t Kháº©u', $vars['focus_keyword'] ?? null);
        self::assertSame('XÆ°á»Ÿng May Balo Xuáº¥t Kháº©u', $vars['keyword'] ?? null);
        self::assertSame('XÆ°á»Ÿng May Balo Xuáº¥t Kháº©u Uy TÃ­n', $vars['topic'] ?? null);
    }

    public function test_resolver_does_not_assign_task_title(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TaskTestInputResolver::class))->getFileName(),
        );
        $start = strpos($src, 'private function withOptionalPromptInputs');
        self::assertNotFalse($start);
        $end = strpos($src, 'private function resolveExistingArticleRewrite', (int) $start);
        self::assertNotFalse($end);
        $chunk = substr($src, (int) $start, (int) $end - (int) $start);

        self::assertStringNotContainsString('$task->title', $chunk);
        self::assertStringNotContainsString("['title'] = \$keyword", $chunk);
        self::assertStringContainsString('effectiveSubject', $chunk);
        self::assertStringContainsString('isNewArticle', $chunk);
    }

    public function test_publish_prefers_h1_over_post_title_seed(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PromptTestPublishService::class))->getFileName(),
        );
        self::assertStringContainsString("\$import['h1_title']", $src);
        self::assertStringContainsString('$h1Title !== \'\'', $src);
        self::assertStringContainsString('? $h1Title', $src);
        self::assertStringContainsString(': $this->resolveTitle', $src);
    }

    public function test_outline_map_seeds_post_title_from_keyword_when_empty(): void
    {
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();
        $map = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'mapInput');
        $map->setAccessible(true);
        $seed = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'seedEmptyPostTitleFromSubject');
        $seed->setAccessible(true);
        $topic = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'enrichTopicInput');
        $topic->setAccessible(true);

        $fields = [
            'post_title' => ['type' => 'string', 'required' => false, 'nullable' => true],
            'keyword' => ['type' => 'string', 'required' => false, 'nullable' => true],
            'topic' => ['type' => 'string', 'required' => false, 'nullable' => true],
        ];

        $mapped = $map->invoke($executor, $fields, [
            'focus_keyword' => 'thá»i trang bá»n vá»¯ng',
        ], []);
        $mapped = $seed->invoke($executor, $mapped, $fields);
        $mapped = $topic->invoke($executor, $mapped, $fields);

        self::assertSame('thá»i trang bá»n vá»¯ng', $mapped['keyword'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $mapped['post_title'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $mapped['topic'] ?? null);
    }

    public function test_compile_alias_mirrors_keyword_focus_and_title(): void
    {
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'expandCompileAliasMirrors');
        $method->setAccessible(true);

        $out = $method->invoke($executor, [
            'keyword' => 'thá»i trang bá»n vá»¯ng',
            'post_title' => 'thá»i trang bá»n vá»¯ng',
            'topic' => 'thá»i trang bá»n vá»¯ng',
        ]);

        self::assertSame('thá»i trang bá»n vá»¯ng', $out['keyword'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $out['focus_keyword'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $out['post_title'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $out['title'] ?? null);
        self::assertSame('thá»i trang bá»n vá»¯ng', $out['topic'] ?? null);
    }

    public function test_compile_alias_does_not_override_explicit_values(): void
    {
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'expandCompileAliasMirrors');
        $method->setAccessible(true);

        $out = $method->invoke($executor, [
            'keyword' => 'kw-a',
            'focus_keyword' => 'kw-b-explicit',
            'post_title' => 'title-a',
            'title' => 'title-b-explicit',
        ]);

        self::assertSame('kw-a', $out['keyword'] ?? null);
        self::assertSame('kw-b-explicit', $out['focus_keyword'] ?? null);
        self::assertSame('title-a', $out['post_title'] ?? null);
        self::assertSame('title-b-explicit', $out['title'] ?? null);
    }

    public function test_site_description_alias_mapped_from_short_description(): void
    {
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();
        $map = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'mapInput');
        $map->setAccessible(true);
        $expand = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'expandCompileAliasMirrors');
        $expand->setAccessible(true);

        $fields = [
            'site_description' => ['type' => 'string', 'required' => false, 'nullable' => true],
            'keyword' => ['type' => 'string', 'required' => false, 'nullable' => true],
        ];
        $mapped = $map->invoke($executor, $fields, [
            'site_short_description' => 'Shop balo xuáº¥t kháº©u',
            'keyword' => 'balo',
        ], []);
        $compile = $expand->invoke($executor, $mapped);

        self::assertSame('Shop balo xuáº¥t kháº©u', $mapped['site_description'] ?? null);
        self::assertSame('Shop balo xuáº¥t kháº©u', $compile['site_short_description'] ?? null);
        self::assertSame('Shop balo xuáº¥t kháº©u', $compile['site_description'] ?? null);
    }

    public function test_legacy_compile_uses_expand_mirrors_not_full_payload_merge(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PromptHookExplicitBindingExecutor::class))->getFileName(),
        );
        self::assertStringContainsString('expandCompileAliasMirrors', $src);
        self::assertStringContainsString('seedEmptyPostTitleFromSubject', $src);
        self::assertStringNotContainsString('array_merge($variables, $input)', $src);
    }

    public function test_rerun_paths_reuse_resolve_for_project_task(): void
    {
        $runSrc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php',
        );
        self::assertStringContainsString('resolveForProjectTask', $runSrc);

        $createSrc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/CreateArticlesFromTaskService.php',
        );
        self::assertStringContainsString('runOutlineThenArticleForContext', $createSrc);
        self::assertStringContainsString('runRerunFromStepForContext', $createSrc);
    }

    public function test_canonical_helper_is_single_source_for_effective_subject(): void
    {
        self::assertSame(
            ContentProjectItemIdentity::topic('T', 'k'),
            ContentProjectItemIdentity::effectiveSubject('T', 'k'),
        );
        self::assertSame(
            ContentProjectItemIdentity::topic(null, 'k'),
            ContentProjectItemIdentity::effectiveSubject('', 'k'),
        );
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function applyOptionalInputs(
        bool $isNewArticle,
        array $existing,
        string $keyword,
        string $title,
    ): array {
        $resolver = (new ReflectionClass(TaskTestInputResolver::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(TaskTestInputResolver::class, 'withOptionalPromptInputs');
        $method->setAccessible(true);

        $context = new TaskTestContext(
            article: null,
            isNewArticle: $isNewArticle,
            matchedBy: null,
            variables: $existing,
            summary: 'test',
            siteId: 1,
            postType: 'article',
        );

        /** @var TaskTestContext $out */
        $out = $method->invoke($resolver, $context, $keyword, $title, '');

        return $out->variables;
    }
}
