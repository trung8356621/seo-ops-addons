<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Outline / Vocabulary must not inherit Writing pass-mode (single_pass / sectioned).
 */
final class OutlineVocabularyPassModeIsolationTest extends TestCase
{
    public function test_outline_history_does_not_invent_single_pass(): void
    {
        $item = $this->normalizeHistoryItem(
            hookKey: 'article.outline.structure.generate',
            snapshot: [
                'compiled_prompt' => 'outline prompt',
                'candidate_model' => 'nvidia/nemotron:free',
                'is_free_candidate' => true,
                'hook_key' => 'article.outline.structure.generate',
            ],
            step: [
                'type' => 'prompt',
                'prompt_name' => 'Outline',
                'status' => 'completed',
                'hook_key' => 'article.outline.structure.generate',
            ],
        );

        self::assertSame('', (string) ($item['strategy_resolved'] ?? ''));
        self::assertSame('', (string) ($item['generation_strategy'] ?? ''));
        self::assertSame('', (string) ($item['strategy_source'] ?? ''));
    }

    public function test_vocabulary_history_does_not_invent_single_pass(): void
    {
        $item = $this->normalizeHistoryItem(
            hookKey: 'article.vocabulary.generate',
            snapshot: [
                'compiled_prompt' => 'vocab prompt',
                'candidate_model' => 'nvidia/nemotron:free',
                'hook_key' => 'article.vocabulary.generate',
            ],
            step: [
                'type' => 'prompt',
                'prompt_name' => 'Vocabulary',
                'status' => 'completed',
                'hook_key' => 'article.vocabulary.generate',
            ],
        );

        self::assertSame('', (string) ($item['strategy_resolved'] ?? ''));
        self::assertNotSame('single_pass', (string) ($item['strategy_resolved'] ?? ''));
    }

    public function test_writing_history_still_defaults_single_pass_when_missing(): void
    {
        $item = $this->normalizeHistoryItem(
            hookKey: 'article.content.generate',
            snapshot: [
                'compiled_prompt' => 'write',
                'candidate_model' => 'anthropic/claude',
                'hook_key' => 'article.content.generate',
            ],
            step: [
                'type' => 'prompt',
                'prompt_name' => 'Writing',
                'status' => 'completed',
                'hook_key' => 'article.content.generate',
            ],
        );

        self::assertSame('single_pass', (string) ($item['strategy_resolved'] ?? ''));
    }

    public function test_legacy_outline_history_with_single_pass_still_renders(): void
    {
        $item = $this->normalizeHistoryItem(
            hookKey: 'article.outline.structure.generate',
            snapshot: [
                'compiled_prompt' => 'outline',
                'strategy_resolved' => 'single_pass',
                'strategy_source' => 'default',
                'generation_shape' => 'single_pass',
                'hook_key' => 'article.outline.structure.generate',
            ],
            step: [
                'type' => 'prompt',
                'prompt_name' => 'Outline',
                'status' => 'completed',
                'hook_key' => 'article.outline.structure.generate',
            ],
        );

        self::assertSame('single_pass', (string) ($item['strategy_resolved'] ?? ''));
        self::assertSame('default', (string) ($item['strategy_source'] ?? ''));
    }

    public function test_prompt_runner_omits_pass_mode_when_variables_have_none(): void
    {
        $method = new ReflectionMethod(PromptRunnerService::class, 'strategySnapshotFields');
        $method->setAccessible(true);
        $service = $this->app->make(PromptRunnerService::class);

        $fields = $method->invoke($service, [
            'topic' => 'dogs',
            'hook_key' => 'article.outline.structure.generate',
        ]);

        self::assertArrayNotHasKey('strategy_resolved', $fields);
        self::assertArrayNotHasKey('generation_strategy', $fields);
        self::assertArrayNotHasKey('generation_shape', $fields);
    }

    public function test_prompt_runner_keeps_writing_pass_mode_when_stamped(): void
    {
        $method = new ReflectionMethod(PromptRunnerService::class, 'strategySnapshotFields');
        $method->setAccessible(true);
        $service = $this->app->make(PromptRunnerService::class);

        $fields = $method->invoke($service, [
            'generation_shape' => 'single_pass',
            'generation_strategy' => 'single_pass',
            'hook_key' => 'article.content.generate',
        ]);

        self::assertSame('single_pass', $fields['strategy_resolved'] ?? null);
        self::assertSame('single_pass', $fields['generation_strategy'] ?? null);
    }

    public function test_binding_executor_strips_writing_pass_keys_for_non_writing_hooks(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/PromptHooks/Runtime/PromptHookExplicitBindingExecutor.php',
        );
        self::assertStringContainsString('Writing pass-mode only', $src);
        self::assertStringContainsString('unset($variables[$writingPassKey])', $src);
        self::assertStringContainsString('$isWritingHook ? $this->strategyResolver->resolve($variables) : null', $src);
        self::assertTrue(class_exists(PromptHookExplicitBindingExecutor::class));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function normalizeHistoryItem(string $hookKey, array $snapshot, array $step): array
    {
        $service = new ArticlePromptRunHistoryService();
        $method = new ReflectionMethod($service, 'normalizePromptItem');
        $method->setAccessible(true);

        $result = new PromptResult();
        $result->forceFill([
            'prompt_id' => 1,
            'status' => 'completed',
            'output_text' => 'ok',
            'input_snapshot' => $snapshot,
            'token_usage' => [],
        ]);
        $result->id = random_int(8000, 8999);
        $result->setRelation('prompt', null);

        /** @var array<string, mixed> $item */
        $item = $method->invoke($service, $step, $result, 1, 1, 0);
        self::assertSame($hookKey, (string) ($item['hook_key'] ?? $hookKey));

        return $item;
    }
}
