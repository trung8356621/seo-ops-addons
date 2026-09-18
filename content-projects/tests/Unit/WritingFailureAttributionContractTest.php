<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFailedStepResumeResolver;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WritingFailureAttributionContractTest extends TestCase
{
    public function test_writing_failure_is_not_labelled_as_outline_from_content_hooks(): void
    {
        $resolver = new ContentProjectFailedStepResumeResolver(new ArtifactReusePolicy);
        $method = new ReflectionMethod($resolver, 'classifyErrorMessage');
        $method->setAccessible(true);

        self::assertSame(
            ContentProjectRerunFromStep::Article->value,
            $method->invoke($resolver, 'Khối Prompt — Viết bài theo dàn ý: Writing generation failed: DeepSeek không trả về nội dung.'),
        );
        self::assertSame(
            ContentProjectRerunFromStep::Article->value,
            $method->invoke($resolver, 'article.content.generate failed: empty output'),
        );
    }

    public function test_outline_generation_failed_prefix_maps_to_outline_when_not_writing(): void
    {
        $resolver = new ContentProjectFailedStepResumeResolver(new ArtifactReusePolicy);
        $method = new ReflectionMethod($resolver, 'classifyErrorMessage');
        $method->setAccessible(true);

        self::assertSame(
            ContentProjectRerunFromStep::Outline->value,
            $method->invoke($resolver, 'Outline generation failed: DeepSeek không trả về nội dung.'),
        );
    }

    public function test_summarize_workflow_failure_rewrites_outline_prefix_when_hook_is_content(): void
    {
        $service = (new ReflectionClass(CreateArticlesFromTaskService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CreateArticlesFromTaskService::class, 'summarizeWorkflowFailure');
        $method->setAccessible(true);

        /** @var array{message: string, failed_step: ?array} $out */
        $out = $method->invoke($service, [[
            'status' => 'failed',
            'title' => 'Khối Prompt',
            'prompt_name' => 'Dẫn ý bài viết',
            'hook_key' => 'article.content.generate',
            'message' => 'Outline generation failed: DeepSeek không trả về nội dung.',
        ]]);

        self::assertStringContainsString('Writing generation failed:', $out['message']);
        self::assertStringNotContainsString('Outline generation failed:', $out['message']);
        self::assertSame('article.content.generate', $out['failed_step']['hook_key'] ?? null);
        self::assertStringContainsString('article.content.generate', $out['message']);
    }

    public function test_rerun_writing_catalog_maps_to_article_step(): void
    {
        $catalog = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Support/ContentProject/ContentProjectItemActionCatalog.php'
        );
        self::assertStringContainsString("key: 'rerun_writing'", $catalog);
        self::assertStringContainsString('RerunProjectItemStepCommand::class', $catalog);
        self::assertStringContainsString("singleMethod: 'regenArticle'", $catalog);
    }
}
