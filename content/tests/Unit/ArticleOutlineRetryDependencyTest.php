<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepCatalogService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepRetryService;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ArticleOutlineRetryDependencyTest extends TestCase
{
    private function fixtureArtifact(string $outline = '## Heading one', string $vocab = 'term: meaning'): string
    {
        return ArticleGenerationInputResolver::OUTLINE_START."\n{$outline}\n"
            .ArticleGenerationInputResolver::OUTLINE_END."\n"
            .ArticleGenerationInputResolver::VOCABULARY_START."\n{$vocab}\n"
            .ArticleGenerationInputResolver::VOCABULARY_END;
    }

    private function generationResolver(ArticleOutlineResolver $outline): ArticleGenerationInputResolver
    {
        return new class($outline) extends ArticleGenerationInputResolver
        {
            protected function fetchSuccessfulRunItems(int $articleId, ?int $preferRunId): \Illuminate\Support\Collection
            {
                return collect();
            }
        };
    }

    public function test_resolver_rejects_empty_and_invalid_outline(): void
    {
        $resolver = new ArticleOutlineResolver($this->parserReturning([]));

        self::assertFalse($resolver->isUsable(''));
        self::assertFalse($resolver->isUsable('   '));
        self::assertFalse($resolver->isUsable('{}'));
        self::assertFalse($resolver->isUsable('[]'));
        self::assertFalse($resolver->isUsable('null'));
        self::assertFalse($resolver->isUsable('short'));
    }

    public function test_resolver_accepts_markdown_with_headings(): void
    {
        $resolver = new ArticleOutlineResolver($this->parserReturning([
            ['level' => 2, 'text' => 'Giá»›i thiá»‡u', 'children' => []],
        ]));

        $markdown = "## Giá»›i thiá»‡u\n### Ã 1\n## Káº¿t luáº­n";
        self::assertTrue($resolver->isUsable($markdown));
    }

    public function test_resolver_reads_canonical_article_meta(): void
    {
        $resolver = new ArticleOutlineResolver($this->parserReturning([
            ['level' => 2, 'text' => 'A', 'children' => []],
        ]));

        $article = new SeoArticle;
        $article->setRelation('articleMetas', collect([
            (new ArticleMeta)->forceFill([
                'meta_key' => ArticleOutlineResolver::META_KEY,
                'meta_value' => "## A\n### B",
            ]),
        ]));

        self::assertTrue($resolver->hasUsableOutline($article));
        self::assertSame("## A\n### B", $resolver->resolveMarkdown($article));
    }

    public function test_apply_completed_outline_prompt_sets_direct_publish_outline(): void
    {
        $runner = (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor();
        $state = new WorkflowExecutionState;
        $outline = $this->fixtureArtifact('## Heading one\n### Child', 'term: meaning');

        $method = new ReflectionMethod(TaskWorkflowTestRunner::class, 'applyCompletedStepToState');
        $method->setAccessible(true);
        $method->invoke($runner, [
            'node_id' => 'outline-node',
            'type' => 'prompt',
            'status' => 'completed',
            'output' => $outline,
            'outputs' => ['out_main' => $outline],
            'outline_markdown' => $outline,
            'persists_as_outline' => true,
        ], $state);

        self::assertSame($outline, $state->meta['direct_publish_outline_markdown'] ?? null);
        self::assertSame($outline, $state->lastPromptOutput);
        self::assertSame($outline, $state->nodeOutputs['outline-node']['out_main'] ?? null);
    }

    public function test_assert_dependencies_passes_after_canonical_outline_present(): void
    {
        $outlineResolver = new ArticleOutlineResolver($this->parserReturning([
            ['level' => 2, 'text' => 'A', 'children' => []],
        ]));
        $artifact = $this->fixtureArtifact('## A\n### Detail enough for content', 'vocab body');

        $article = new SeoArticle;
        $article->setRelation('articleMetas', collect([
            (new ArticleMeta)->forceFill([
                'meta_key' => ArticleOutlineResolver::META_KEY,
                'meta_value' => $artifact,
            ]),
        ]));

        $task = new SeoProjectTask;
        $task->article_id = 99;
        $task->setRelation('article', $article);

        $service = (new ReflectionClass(SeoProjectWorkflowStepRetryService::class))
            ->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('articleGenerationInput');
        $prop->setAccessible(true);
        $prop->setValue($service, $this->generationResolver($outlineResolver));

        $assert = new ReflectionMethod(SeoProjectWorkflowStepRetryService::class, 'assertDependencies');
        $assert->setAccessible(true);

        $error = $assert->invoke($service, $task, [
            'kind' => 'content',
            'depends_on_kinds' => ['outline'],
        ]);
        self::assertNull($error);
    }

    public function test_assert_dependencies_blocks_when_canonical_outline_missing(): void
    {
        $outlineResolver = new ArticleOutlineResolver($this->parserReturning([]));

        $article = new SeoArticle;
        $article->setRelation('articleMetas', collect([]));

        $task = new SeoProjectTask;
        $task->article_id = 99;
        $task->setRelation('article', $article);

        $service = (new ReflectionClass(SeoProjectWorkflowStepRetryService::class))
            ->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($service);
        $prop = $ref->getProperty('articleGenerationInput');
        $prop->setAccessible(true);
        $prop->setValue($service, $this->generationResolver($outlineResolver));

        $assert = new ReflectionMethod(SeoProjectWorkflowStepRetryService::class, 'assertDependencies');
        $assert->setAccessible(true);

        $error = $assert->invoke($service, $task, [
            'kind' => 'content',
            'depends_on_kinds' => ['outline'],
        ]);
        self::assertSame(ArticleGenerationInputResolver::REJECT_MESSAGE, $error);
    }

    public function test_content_prior_seeds_outline_from_article_when_snapshot_empty(): void
    {
        $outlineResolver = new ArticleOutlineResolver($this->parserReturning([
            ['level' => 2, 'text' => 'Seeded', 'children' => []],
        ]));
        $outline = $this->fixtureArtifact('## Seeded outline marker UNIQUE_OUTLINE_XYZ\n### Child', 'vocab UNIQUE');

        $article = new SeoArticle;
        $article->setRelation('articleMetas', collect([
            (new ArticleMeta)->forceFill([
                'meta_key' => ArticleOutlineResolver::META_KEY,
                'meta_value' => $outline,
            ]),
        ]));

        $task = new SeoProjectTask;
        $task->id = 1;

        $catalog = $this->createMock(SeoProjectWorkflowStepCatalogService::class);
        $catalog->method('firstPromptNodeIdForKind')->willReturn('outline-1');

        $service = (new ReflectionClass(SeoProjectWorkflowStepRetryService::class))
            ->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($service);
        foreach ([
            'outlineResolver' => $outlineResolver,
            'articleGenerationInput' => $this->generationResolver($outlineResolver),
            'catalog' => $catalog,
        ] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        $method = new ReflectionMethod(SeoProjectWorkflowStepRetryService::class, 'ensureOutlinePriorFromArticle');
        $method->setAccessible(true);
        /** @var list<array<string, mixed>> $prior */
        $prior = $method->invoke($service, $task, [], $article);

        self::assertCount(1, $prior);
        self::assertSame('outline-1', $prior[0]['node_id']);
        self::assertStringContainsString('UNIQUE_OUTLINE_XYZ', (string) $prior[0]['output']);
        self::assertStringContainsString(ArticleGenerationInputResolver::OUTLINE_START, (string) $prior[0]['output']);
        self::assertTrue((bool) ($prior[0]['persists_as_outline'] ?? false));
    }

    public function test_retry_service_wires_outline_persist_before_success(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepRetryService.php'
        );

        self::assertStringContainsString('persistOutlineStepResult', $source);
        self::assertStringContainsString('ensureOutlinePriorFromArticle', $source);
        self::assertStringContainsString('ArticleGenerationInputResolver', $source);
        self::assertStringContainsString("(\$stepMeta['kind'] ?? '') === 'outline'", $source);
        self::assertStringContainsString('outlineResolver->persist', $source);
        self::assertStringContainsString('articleGenerationInput->resolveForArticle', $source);
    }

    public function test_runner_captures_outline_prompt_output_into_state(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskWorkflowTestRunner.php'
        );

        self::assertStringContainsString('captureOutlinePromptOutput', $source);
        self::assertStringContainsString("'persists_as_outline'", $source);
        self::assertStringContainsString('direct_publish_outline_markdown', $source);
        self::assertStringContainsString('resolveArticleGenerationInputForPrompt', $source);
        self::assertStringContainsString('ArticleGenerationInputResolver', $source);
    }

    /**
     * @param  list<array<string, mixed>>  $parsed
     */
    private function parserReturning(array $parsed): WorkflowParserService
    {
        $parser = $this->getMockBuilder(WorkflowParserService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['parseOutline'])
            ->getMock();
        $parser->method('parseOutline')->willReturn($parsed);

        return $parser;
    }
}
