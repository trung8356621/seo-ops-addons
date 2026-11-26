<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;
use PHPUnit\Framework\TestCase;

final class PromptSplitStrategyRegistryShortHookReserveTest extends TestCase
{
    public function test_meta_description_stays_direct_fit(): void
    {
        $strategy = (new PromptSplitStrategyRegistry)->forHook('article.meta_description_suggestion');

        self::assertSame(PromptSplitClass::DirectFit, $strategy->splitClass());
        self::assertFalse($strategy->supportsSplit());
    }

    public function test_meta_description_reasoning_reserve_is_8192(): void
    {
        $strategy = (new PromptSplitStrategyRegistry)->forHook('article.meta_description_suggestion');
        $capability = $this->capability(
            contextWindow: 128_000,
            maxOutputTokens: 8192,
            reasoning: true,
        );

        self::assertSame(8192, $strategy->estimateOutputReserve([], $capability));
    }

    public function test_meta_description_non_reasoning_reserve_is_4096(): void
    {
        $strategy = (new PromptSplitStrategyRegistry)->forHook('article.meta_description_suggestion');
        $capability = $this->capability(
            contextWindow: 128_000,
            maxOutputTokens: 8192,
            reasoning: false,
        );

        self::assertSame(4096, $strategy->estimateOutputReserve([], $capability));
    }

    public function test_meta_description_reserve_caps_by_max_output_tokens(): void
    {
        $strategy = (new PromptSplitStrategyRegistry)->forHook('article.meta_description_suggestion');

        self::assertSame(1024, $strategy->estimateOutputReserve([], $this->capability(
            contextWindow: 128_000,
            maxOutputTokens: 1024,
            reasoning: true,
        )));
        self::assertSame(2048, $strategy->estimateOutputReserve([], $this->capability(
            contextWindow: 128_000,
            maxOutputTokens: 2048,
            reasoning: false,
        )));
    }

    public function test_meta_description_preflight_fits_large_context_and_rejects_tight_context(): void
    {
        $registry = new PromptSplitStrategyRegistry;
        $strategy = $registry->forHook('article.meta_description_suggestion');
        $preflight = new PromptBudgetPreflightService;
        $compiled = str_repeat('meta prompt token sample ', 20);

        $fits = $preflight->planWithCapability(
            $this->capability(contextWindow: 128_000, maxOutputTokens: 8192, reasoning: true),
            $strategy,
            $compiled,
        );
        self::assertTrue($fits->requestFits);
        self::assertSame(8192, $fits->requestedMaxOutputTokens);
        self::assertTrue($fits->outputCapabilitySufficient);

        $tight = $preflight->planWithCapability(
            $this->capability(contextWindow: 4096, maxOutputTokens: 8192, reasoning: true),
            $strategy,
            $compiled,
        );
        self::assertFalse($tight->requestFits);
        self::assertSame(8192, $tight->desiredOutputTokens);
        self::assertSame(8192, $tight->requestedMaxOutputTokens);
    }

    public function test_title_suggestion_reserve_remains_fixed(): void
    {
        $strategy = (new PromptSplitStrategyRegistry)->forHook('article.title_suggestion');
        $capability = $this->capability(
            contextWindow: 128_000,
            maxOutputTokens: 8192,
            reasoning: true,
        );

        self::assertSame(256, $strategy->estimateOutputReserve([], $capability));
    }

    private function capability(int $contextWindow, int $maxOutputTokens, bool $reasoning): ModelContextCapability
    {
        return new ModelContextCapability(
            contextWindow: $contextWindow,
            maxOutputTokens: $maxOutputTokens,
            capabilitySource: 'test',
            estimatorFamily: 'char_heuristic_v1',
            isReasoningModel: $reasoning,
        );
    }
}
