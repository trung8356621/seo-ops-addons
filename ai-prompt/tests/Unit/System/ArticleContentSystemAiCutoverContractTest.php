<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit\System;

use App\System\Ai\Client\DefaultSystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Capability\SystemCapabilityDefinition;
use App\System\Capability\SystemCapabilityHandler;
use App\System\Capability\SystemCapabilityRegistry;
use App\System\Support\CapabilityModeResolver;
use App\System\Support\SystemExecutionMode;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\AiPrompt\System\ArticleContentGenerateCapabilityHandler;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ArticleContentSystemAiCutoverContractTest extends TestCase
{
    public function test_capability_key_is_canonical_hook_not_duplicate(): void
    {
        self::assertSame('article.content.generate', ArticleContentGenerateCapabilityHandler::KEY);
        self::assertSame(
            \Omnichannel\Addons\Content\Services\ArticleWritingExecutionService::HOOK_KEY,
            ArticleContentGenerateCapabilityHandler::KEY,
        );
    }

    public function test_executor_always_enters_system_ai_boundary_for_writing_hooks(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PromptHookExplicitBindingExecutor::class))->getFileName()
        );
        self::assertStringContainsString('shouldExecuteViaSystemAi', $src);
        self::assertStringContainsString('executeWritingViaSystemAi', $src);
        self::assertStringContainsString('via_system_ai', $src);
        self::assertStringContainsString('Writing generation failed:', $src);
        self::assertStringContainsString(ArticleContentGenerateCapabilityHandler::KEY, $src);
        // Mode selection belongs to DefaultSystemAiClient — not remote|shadow gate here.
        self::assertStringNotContainsString('SystemExecutionMode::Remote', $src);
        self::assertStringNotContainsString('SystemExecutionMode::Shadow', $src);
        self::assertStringContainsString('Writing hooks always enter SystemAiClient when bound', $src);
    }

    public function test_legacy_mode_is_default_when_capability_unset(): void
    {
        $resolver = new CapabilityModeResolver();
        self::assertSame(SystemExecutionMode::Legacy, $resolver->resolve('article.content.generate', 'ai'));
    }

    public function test_system_ai_remote_path_uses_capability_handler_output(): void
    {
        $registry = new SystemCapabilityRegistry();
        $registry->register(new SystemCapabilityDefinition(
            key: ArticleContentGenerateCapabilityHandler::KEY,
            owner: 'ai-prompt',
            handler: new class implements SystemCapabilityHandler
            {
                public function handle(array $input, array $context = []): array
                {
                    return [
                        'output' => 'written body',
                        'raw' => 'written body',
                        'hook_key' => ArticleContentGenerateCapabilityHandler::KEY,
                        'execution_source' => 'test_handler',
                        'prompt_result_id' => 42,
                        'correlation_id' => 'corr-test',
                    ];
                }
            },
            sideEffectFree: true,
        ));

        $client = new DefaultSystemAiClient(
            modes: new CapabilityModeResolver(),
            local: new LegacyLocalAiTransport($registry),
        );

        $result = $client->execute(new AiExecutionRequest(
            capability: ArticleContentGenerateCapabilityHandler::KEY,
            input: ['prompt_id' => 1],
            correlation: [
                'stage' => 'writing',
                'article_id' => 8553,
                'project_item_id' => 8799,
                'content_project_id' => 900,
            ],
        ));

        self::assertSame('completed', $result->status);
        self::assertSame('written body', $result->output['output'] ?? null);
        self::assertSame(42, $result->output['prompt_result_id'] ?? null);
        self::assertSame('writing', $result->meta['correlation']['stage'] ?? $result->toArray()['meta']['correlation']['stage'] ?? 'writing');
    }

    public function test_outline_role_node_does_not_misclassify_writing_title_with_dan_y(): void
    {
        $runner = (new ReflectionClass(TaskWorkflowTestRunner::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(TaskWorkflowTestRunner::class, 'isOutlineRoleNode');
        $method->setAccessible(true);

        $writingNode = [
            'id' => 'writing',
            'title' => 'Viết bài theo dàn ý',
            'data' => [
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                'hook_key' => 'article.content.generate',
            ],
        ];

        self::assertFalse($method->invoke($runner, $writingNode, 'article.content.generate'));
        self::assertFalse($method->invoke($runner, $writingNode, null));

        $outlineNode = [
            'id' => 'outline',
            'title' => 'Dàn ý bài viết',
            'data' => [
                'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
            ],
        ];
        self::assertTrue($method->invoke($runner, $outlineNode, 'article.outline.structure.generate'));
        self::assertTrue($method->invoke($runner, $outlineNode, null));
    }

    public function test_rerun_from_writing_uses_content_node_not_outline_split(): void
    {
        $createSrc = (string) file_get_contents(
            dirname(__DIR__, 4).'/content-projects/src/Services/CreateArticlesFromTaskService.php'
        );
        self::assertStringContainsString('ArticleWritingExecutionMode::ContentNode', $createSrc);
        self::assertStringContainsString('ContentProjectRerunFromStep::Article', $createSrc);

        $writingSrc = (string) file_get_contents(
            dirname(__DIR__, 4).'/content/src/Services/ArticleWritingExecutionService.php'
        );
        self::assertStringContainsString('seedOutlineFromArticle: true', $writingSrc);
        self::assertStringContainsString('Không chạy outline lại', $writingSrc);
    }

    public function test_no_database_migration_in_system_writing_cutover(): void
    {
        $handler = (string) file_get_contents(
            (new ReflectionClass(ArticleContentGenerateCapabilityHandler::class))->getFileName()
        );
        self::assertStringContainsString('omi_seo_ai', $handler);
        self::assertStringNotContainsString('Schema::', $handler);
        self::assertStringNotContainsString('create_table', $handler);

        $migrations = glob(dirname(__DIR__, 3).'/database/migrations/*system*') ?: [];
        self::assertSame([], $migrations);
    }

    public function test_provider_registers_article_content_capability(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/AiPromptServiceProvider.php'
        );
        self::assertStringContainsString('ArticleContentGenerateCapabilityHandler', $src);
        self::assertStringContainsString('SystemCapabilityRegistry', $src);
    }

    public function test_remote_correlation_fields_are_merged_not_overwritten(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleContentGenerateCapabilityHandler::class))->getFileName()
        );
        self::assertStringContainsString(
            "if (! array_key_exists(\$key, \$contextExtras) || \$contextExtras[\$key] === null || \$contextExtras[\$key] === '')",
            $src,
        );
        foreach (['article_id', 'project_item_id', 'content_project_id', 'run_id', 'node_id', 'canonical_prompt_key', 'retry_attempt', 'correlation_id', 'stage'] as $field) {
            self::assertStringContainsString("'".$field."'", $src);
        }
    }
}
