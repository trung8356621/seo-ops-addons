<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService;
use Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator;
use Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReason;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;
use PHPUnit\Framework\TestCase;

/**
 * Outline/Vocabulary output budget must be hook + capability aware — not fixed 2048.
 */
final class OutlineVocabularyOutputBudgetTest extends TestCase
{
    private PromptBudgetPreflightService $preflight;

    private PromptSplitStrategyRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preflight = new PromptBudgetPreflightService();
        $this->registry = new PromptSplitStrategyRegistry();
    }

    /** T1 — reasoning + maxOut 8192 → requested > 2048 (8192) */
    public function test_t1_outline_reasoning_8192_requests_above_old_ceiling(): void
    {
        $capability = $this->capability(maxOut: 8192, reasoning: true);
        $strategy = $this->registry->forHook('article.outline.structure.generate');
        $desired = $strategy->estimateOutputReserve([], $capability);
        $plan = $this->preflight->planWithCapability(
            $capability,
            $strategy,
            'Write an outline for balo quat.',
            [
                'desired_output_tokens' => $desired,
                'minimum_required_output_tokens' => max(64, (int) floor($desired * 0.35)),
            ],
        );

        self::assertSame(8192, $desired);
        self::assertGreaterThan(2048, $plan->requestedMaxOutputTokens);
        self::assertSame(8192, $plan->requestedMaxOutputTokens);
        self::assertSame(8192, $plan->desiredOutputTokens);
        self::assertSame(8192, $plan->modelMaxOutputTokens);
        self::assertArrayHasKey('desired_output_tokens', $plan->toDiagnostics());
        self::assertArrayHasKey('requested_max_output_tokens', $plan->toDiagnostics());
        self::assertArrayHasKey('model_max_output_tokens', $plan->toDiagnostics());
    }

    /** T2 — non-reasoning maxOut 4096 → requested ≤ 4096, not 2048 */
    public function test_t2_outline_non_reasoning_uses_4096_not_2048(): void
    {
        $capability = $this->capability(maxOut: 4096, reasoning: false);
        $strategy = $this->registry->forHook('article.outline.structure.generate');
        $desired = $strategy->estimateOutputReserve([], $capability);
        $plan = $this->preflight->planWithCapability(
            $capability,
            $strategy,
            'Write an outline.',
            ['desired_output_tokens' => $desired],
        );

        self::assertSame(4096, $desired);
        self::assertSame(4096, $plan->requestedMaxOutputTokens);
        self::assertNotSame(2048, $plan->requestedMaxOutputTokens);
    }

    /** T3 — real capability max 2048 → never exceed */
    public function test_t3_caps_at_real_model_max_2048(): void
    {
        $capability = $this->capability(maxOut: 2048, reasoning: true);
        $strategy = $this->registry->forHook('article.outline.structure.generate');
        $desired = $strategy->estimateOutputReserve([], $capability);
        $plan = $this->preflight->planWithCapability(
            $capability,
            $strategy,
            'Write an outline.',
            ['desired_output_tokens' => $desired],
        );

        self::assertSame(2048, $desired);
        self::assertSame(2048, $plan->requestedMaxOutputTokens);
        self::assertSame(2048, $plan->modelMaxOutputTokens);
    }

    /** T4 — vocabulary follows same policy */
    public function test_t4_vocabulary_same_capability_aware_policy(): void
    {
        $reasoning = $this->capability(maxOut: 8192, reasoning: true);
        $plain = $this->capability(maxOut: 4096, reasoning: false);
        $strategy = $this->registry->forHook('article.vocabulary.generate');

        self::assertSame(8192, $strategy->estimateOutputReserve([], $reasoning));
        self::assertSame(4096, $strategy->estimateOutputReserve([], $plain));

        $plan = $this->preflight->planWithCapability(
            $reasoning,
            $strategy,
            'Vocabulary research.',
            ['desired_output_tokens' => 8192],
        );
        self::assertSame(8192, $plan->requestedMaxOutputTokens);
    }

    /** T5 — small hooks unchanged */
    public function test_t5_small_hooks_remain_unchanged(): void
    {
        $capability = $this->capability(maxOut: 8192, reasoning: true);
        $map = [
            'article.title_suggestion' => 256,
            'article.meta_description_suggestion' => 320,
            'article.faq.generate' => 800,
            'article.comment.generate' => 400,
        ];
        foreach ($map as $hook => $reserve) {
            $strategy = $this->registry->forHook($hook);
            self::assertSame(PromptSplitClass::DirectFit, $strategy->splitClass());
            self::assertSame($reserve, $strategy->estimateOutputReserve([], $capability));
            $plan = $this->preflight->planWithCapability(
                $capability,
                $strategy,
                'short',
                ['desired_output_tokens' => $reserve],
            );
            self::assertSame($reserve, $plan->requestedMaxOutputTokens);
        }
    }

    /** T6 — article.content.generate reserve unchanged at 4096 */
    public function test_t6_article_content_reserve_unchanged(): void
    {
        $capability = $this->capability(maxOut: 8192, reasoning: false);
        $strategy = $this->registry->forHook('article.content.generate');
        self::assertSame(PromptSplitClass::DirectFit, $strategy->splitClass());
        self::assertSame(4096, $strategy->estimateOutputReserve([], $capability));
        $plan = $this->preflight->planWithCapability(
            $capability,
            $strategy,
            'Write article body.',
            ['desired_output_tokens' => 4096],
        );
        self::assertSame(4096, $plan->requestedMaxOutputTokens);
    }

    /** T7 — context budget still fails when input + requested exceeds safe context */
    public function test_t7_context_budget_still_fails(): void
    {
        $capability = new ModelContextCapability(
            contextWindow: 3000,
            maxOutputTokens: 8192,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            isReasoningModel: true,
            safetyMarginTokens: 500,
        );
        $strategy = $this->registry->forHook('article.outline.structure.generate');
        $huge = str_repeat('Từ khóa dài và mô tả sản phẩm. ', 400);
        $plan = $this->preflight->planWithCapability(
            $capability,
            $strategy,
            $huge,
            ['desired_output_tokens' => 8192],
        );
        self::assertFalse($plan->requestFits);
        self::assertGreaterThan(0, $plan->safeContextBudget);
        // PHPUnit: assertGreaterThan($expected, $actual) ⇒ $actual > $expected
        self::assertGreaterThan(
            $plan->safeContextBudget,
            $plan->estimatedInputTokens + $plan->requestedMaxOutputTokens,
        );
    }

    /** T8 — finish_reason=length still throws OutputTruncated with usage/budget */
    public function test_t8_true_truncation_still_throws_with_budget_usage(): void
    {
        $usage = [
            'finish_reason' => 'length',
            'completion_tokens' => 8192,
            'budget' => [
                'desired_output_tokens' => 8192,
                'requested_max_output_tokens' => 8192,
                'model_max_output_tokens' => 8192,
            ],
        ];
        $exception = new OutputTruncated(
            'OUTPUT_TRUNCATED: provider terminal reason=output_truncated (finish_reason=length).',
            AiProviderTerminalReason::OutputTruncated,
            'length',
            $usage,
        );
        self::assertSame('length', $exception->providerFinishReason);
        self::assertSame(8192, $exception->usage['budget']['requested_max_output_tokens'] ?? null);
    }

    /** T9 contract — FreeOnly truncation fallback still classified recoverable */
    public function test_t9_output_truncated_still_continues_routing(): void
    {
        $decision = (new \Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier)->classify(
            new OutputTruncated(
                'OUTPUT_TRUNCATED: provider terminal reason=output_truncated (finish_reason=length).',
                AiProviderTerminalReason::OutputTruncated,
                'length',
                [
                    'budget' => [
                        'desired_output_tokens' => 8192,
                        'requested_max_output_tokens' => 8192,
                        'model_max_output_tokens' => 8192,
                    ],
                ],
            ),
        );
        self::assertTrue($decision->shouldContinueRouting());
        self::assertFalse($decision->affectsRuntimeHealth);
    }

    public function test_prompt_runner_no_longer_has_deepseek_outline_desired_special_case(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/PromptRunnerService.php',
        );
        self::assertStringNotContainsString(
            "strtolower(trim(\$routed->model)) === 'deepseek-v4-pro'",
            $src,
        );
        self::assertStringContainsString('assertProviderTerminalReasonEligibleForFailover', $src);
        // Budget stamped before truncation gate for failed-attempt observability.
        $budgetPos = strpos($src, "\$usage['budget'] = \$budgetPlan->toDiagnostics()");
        $gatePos = strpos($src, 'assertProviderTerminalReasonEligibleForFailover($usage)');
        self::assertNotFalse($budgetPos);
        self::assertNotFalse($gatePos);
        self::assertLessThan($gatePos, $budgetPos);
    }

    public function test_default_max_output_inferred_ceiling_is_8192(): void
    {
        self::assertSame(
            8192,
            \Omnichannel\Addons\AiPrompt\Services\ModelContextCapabilityResolver::DEFAULT_MAX_OUTPUT,
        );
    }

    private function capability(int $maxOut, bool $reasoning): ModelContextCapability
    {
        return new ModelContextCapability(
            contextWindow: 128_000,
            maxOutputTokens: $maxOut,
            capabilitySource: 'test',
            estimatorFamily: PromptTokenEstimator::FAMILY_DEFAULT,
            isReasoningModel: $reasoning,
            safetyMarginTokens: 800,
        );
    }
}
