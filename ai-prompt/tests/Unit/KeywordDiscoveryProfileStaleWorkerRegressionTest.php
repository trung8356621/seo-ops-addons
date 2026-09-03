<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use PHPUnit\Framework\TestCase;

/**
 * Regression for 2026-09-03 GenerateNewContentSuggestionsJob run20:
 * stale queue worker kept text.reasoning → deepseek-reasoner (180s × routes).
 * Disk SSOT must map keyword.discovery.structured → text.longform.
 */
final class KeywordDiscoveryProfileStaleWorkerRegressionTest extends TestCase
{
    public function test_keyword_discovery_hook_resolves_text_longform_not_reasoning(): void
    {
        $resolver = new PromptExecutionProfileResolver;
        $profile = $resolver->resolve(null, 'keyword.discovery.structured');

        self::assertSame(AiExecutionProfile::TextLongform, $profile);
        self::assertNotSame(AiExecutionProfile::TextReasoning, $profile);
        self::assertSame('text.longform', $profile->value);
    }

    public function test_runtime_text_reasoning_on_kd_is_stale_worker_signal(): void
    {
        $disk = (new PromptExecutionProfileResolver)
            ->resolve(null, 'keyword.discovery.structured')
            ->value;

        // Evidence from run20 PromptResults 1115–1118: routing.profile = text.reasoning
        $observedOnStuckRun = 'text.reasoning';

        self::assertSame('text.longform', $disk);
        self::assertNotSame(
            $disk,
            $observedOnStuckRun,
            'If a live KD PromptResult still logs text.reasoning while disk says text.longform, restart queue workers.',
        );
    }

    public function test_timeout_fallback_worst_case_formula_for_two_reasoning_routes(): void
    {
        // DeepSeekChatClient / OpenAiCompatibleProtocolAdapter HTTP_TIMEOUT = 180s
        // No Laravel Http::retry() on those paths → HTTP calls ≈ router attempts
        $perRequestTimeoutSec = 180;
        $eligibleRoutes = 2; // deepseek-reasoner + nemotron free (text.reasoning plan)
        $sameRouteHttpRetries = 1;
        $outerBatchRoundsBeforeStagnantStop = 3; // observed run20: 3 batch_trace rows
        $jobTimeoutSec = 900;

        $worstCasePerPromptResult = $sameRouteHttpRetries * $perRequestTimeoutSec * $eligibleRoutes;
        $worstCaseJob = $worstCasePerPromptResult * $outerBatchRoundsBeforeStagnantStop;

        self::assertSame(360, $worstCasePerPromptResult);
        self::assertSame(1080, $worstCaseJob);
        self::assertGreaterThan(
            $jobTimeoutSec,
            $worstCaseJob,
            'Without profile switch / deadline, qty=50 can exceed job timeout=900 via timeout×routes×rounds.',
        );
    }
}
