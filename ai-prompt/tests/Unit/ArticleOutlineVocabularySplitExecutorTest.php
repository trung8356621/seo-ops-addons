<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSplitOutlinePromptsInstaller;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArticleOutlineVocabularySplitExecutorTest extends TestCase
{
    public function test_assemble_ports_wraps_legacy_markers(): void
    {
        $executor = (new ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))
            ->newInstanceWithoutConstructor();
        self::assertInstanceOf(ArticleOutlineVocabularySplitExecutor::class, $executor);

        $ports = $executor->assemblePorts('## Outline body', '- term: meaning');

        self::assertSame('## Outline body', $ports['task_1_outline']);
        self::assertSame('- term: meaning', $ports['task_2_vocabulary']);
        self::assertStringContainsString(ArticleGenerationInputResolver::OUTLINE_START, $ports['total']);
        self::assertStringContainsString(ArticleGenerationInputResolver::VOCABULARY_START, $ports['total']);
        self::assertStringContainsString('## Outline body', $ports['total']);
        self::assertStringContainsString('- term: meaning', $ports['total']);
    }

    public function test_e_split_executor_source_declares_two_hook_calls(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ArticleOutlineVocabularySplitExecutor.php',
        );
        self::assertSame(2, substr_count($source, '$this->hookBindingExecutor->execute('));

        $runnerSource = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString('$this->outlineSplitExecutor->execute(', $runnerSource);
    }

    public function test_f_split_executor_collects_two_prompt_result_ids(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ArticleOutlineVocabularySplitExecutor.php',
        );
        self::assertStringContainsString("'prompt_result_ids' => \$resultIds", $source);
        self::assertStringContainsString('$outlineResult[\'prompt_result_id\']', $source);
        self::assertStringContainsString('$vocabularyResult[\'prompt_result_id\']', $source);

        $runnerSource = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString("'prompt_result_ids' => \$splitResult['prompt_result_ids']", $runnerSource);
        self::assertStringContainsString("'outline_subtask'", $runnerSource);
    }

    public function test_g_legacy_projection_ports_present_on_success(): void
    {
        $executor = (new ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))
            ->newInstanceWithoutConstructor();
        $ports = $executor->assemblePorts('## Outline', '### Holonymy\n- a');

        self::assertArrayHasKey('task_1_outline', $ports);
        self::assertArrayHasKey('task_2_vocabulary', $ports);
        self::assertArrayHasKey('total', $ports);
        self::assertStringContainsString(ArticleGenerationInputResolver::OUTLINE_START, $ports['total']);
        self::assertStringContainsString(ArticleGenerationInputResolver::VOCABULARY_START, $ports['total']);
    }

    public function test_h_split_hooks_map_to_text_reasoning_not_longform(): void
    {
        $resolver = new PromptExecutionProfileResolver;
        self::assertSame(
            AiExecutionProfile::TextReasoning,
            $resolver->resolve(null, ArticleOutlineVocabularySplitExecutor::OUTLINE_STRUCTURE_HOOK),
        );
        self::assertSame(
            AiExecutionProfile::TextReasoning,
            $resolver->resolve(null, ArticleOutlineVocabularySplitExecutor::VOCABULARY_HOOK),
        );
        self::assertSame(
            AiExecutionProfile::TextLongform,
            $resolver->resolve(null, 'article.content.generate'),
        );
    }

    public function test_i_partial_failure_source_preserves_outline_result(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ArticleOutlineVocabularySplitExecutor.php',
        );
        self::assertStringContainsString('Vocabulary generation failed:', $source);
        self::assertStringContainsString('outlineResult: $outlineResult', $source);
        self::assertStringContainsString("'outline_result' => \$outlineResult", $source);

        $runnerSource = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString("(\$splitResult['status'] ?? '') !== 'completed'", $runnerSource);
        self::assertStringContainsString('shouldSkipAfterOutlineFailure', $runnerSource);
    }

    public function test_split_prompt_markdown_is_canonical_from_installer(): void
    {
        self::assertStringContainsString('{{post_title}}', DefaultSplitOutlinePromptsInstaller::OUTLINE_MARKDOWN);
        self::assertStringContainsString('Holonymy', DefaultSplitOutlinePromptsInstaller::VOCABULARY_MARKDOWN);
    }

    public function test_outline_role_node_detects_legacy_outline_hook_on_bound_prompt(): void
    {
        $runnerSource = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/TaskWorkflowTestRunner.php',
        );
        self::assertStringContainsString(
            '$this->isOutlineRoleNode($node, $hookBinding->hookKey)',
            $runnerSource,
        );
        self::assertStringContainsString(
            'ArticleGenerationInputResolver::OUTLINE_HOOK_KEY',
            $runnerSource,
        );
    }
}
