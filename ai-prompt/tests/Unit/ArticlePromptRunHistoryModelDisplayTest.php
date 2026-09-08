<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;
use Omnichannel\Addons\Content\Support\ArticleAiHistoryCardPresenter;
use ReflectionMethod;
use Tests\TestCase;

/**
 * History card must display routing candidate/actual — never silent requested Claude.
 */
final class ArticlePromptRunHistoryModelDisplayTest extends TestCase
{
    public function test_normalize_prompt_item_displays_nemotron_not_requested_claude(): void
    {
        $service = new ArticlePromptRunHistoryService();
        $method = new ReflectionMethod($service, 'normalizePromptItem');
        $method->setAccessible(true);

        $result = new PromptResult();
        $result->forceFill([
            'prompt_id' => 1,
            'status' => 'completed',
            'output_text' => 'hello world',
            'input_snapshot' => [
                'compiled_prompt' => 'write article',
                'requested_model' => 'anthropic/claude-sonnet-4.6',
                'candidate_model' => 'nvidia/nemotron-test:free',
                'actual_provider_model' => null,
                'is_free_candidate' => true,
                'strategy_override' => null,
                'strategy_resolved' => 'single_pass',
                'strategy_source' => 'default',
                'hook_key' => 'article.content.generate',
            ],
            'token_usage' => [],
        ]);
        $result->id = 9001;
        $result->setRelation('prompt', null);

        $item = $method->invoke(
            $service,
            [
                'type' => 'prompt',
                'prompt_name' => 'Viết bài theo dàn ý',
                'status' => 'completed',
                'execution_type' => 'retry',
                'attempt' => 2,
                'ai_model' => 'anthropic/claude-sonnet-4.6',
            ],
            $result,
            1,
            1,
            0,
        );

        self::assertSame('nvidia/nemotron-test:free', $item['model']);
        self::assertSame('anthropic/claude-sonnet-4.6', $item['requested_model']);
        self::assertTrue($item['is_free_candidate']);
        self::assertSame('single_pass', $item['strategy_resolved']);
        self::assertSame('default', $item['strategy_source']);
        self::assertSame('none', $item['strategy_override_label']);
        self::assertSame(
            'nemotron-test · free',
            ArticleAiHistoryCardPresenter::compactModel($item['model'], $item['is_free_candidate']),
        );
        self::assertStringNotContainsString('claude', strtolower((string) $item['model']));
    }

    public function test_normalize_prompt_item_unknown_when_no_candidate_or_actual(): void
    {
        $service = new ArticlePromptRunHistoryService();
        $method = new ReflectionMethod($service, 'normalizePromptItem');
        $method->setAccessible(true);

        $result = new PromptResult();
        $result->forceFill([
            'prompt_id' => 1,
            'status' => 'failed',
            'output_text' => '',
            'error_message' => 'failed',
            'input_snapshot' => [
                'compiled_prompt' => 'x',
                'requested_model' => 'anthropic/claude-sonnet-4.6',
                'strategy_resolved' => 'single_pass',
                'strategy_source' => 'default',
            ],
            'token_usage' => [],
        ]);
        $result->id = 9002;
        $result->setRelation('prompt', null);

        $item = $method->invoke(
            $service,
            [
                'type' => 'prompt',
                'prompt_name' => 'Viết bài',
                'status' => 'failed',
                'ai_model' => 'anthropic/claude-sonnet-4.6',
            ],
            $result,
            1,
            1,
            0,
        );

        self::assertSame('Unknown model', $item['model']);
    }
}
