<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptBudgetException;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
use Omnichannel\Addons\AiPrompt\Services\CanonicalAiTextExecutionService;
use Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CanonicalAiTextExecutionServiceTest extends TestCase
{
    public function test_seeding_hook_is_registered_direct_fit(): void
    {
        $map = (new PromptSplitStrategyRegistry())->classificationMap();
        self::assertArrayHasKey('seeding.comment_generate', $map);
        self::assertSame(PromptSplitClass::DirectFit->value, $map['seeding.comment_generate']);
    }

    public function test_source_uses_router_preflight_and_never_bypasses_budget(): void
    {
        $path = (new ReflectionClass(CanonicalAiTextExecutionService::class))->getFileName();
        $source = (string) file_get_contents((string) $path);
        self::assertStringContainsString('executeWithProfile', $source);
        self::assertStringContainsString('assertSendable', $source);
        self::assertStringContainsString('budget_plan_id', $source);
        self::assertStringContainsString('AiExecutionProfile::TextFast', $source);
        self::assertStringContainsString("unset(\$callOptions['allow_unverified_outbound'])", $source);
        self::assertStringNotContainsString("'allow_unverified_outbound' => true", $source);
        self::assertStringNotContainsString('allow_unverified_outbound = true', $source);
        self::assertStringNotContainsString('SeoAiModel::query', $source);
    }

    public function test_verified_plan_registry_required_before_provider_call(): void
    {
        $preflight = new PromptBudgetPreflightService();
        $capability = new ModelContextCapability(
            contextWindow: 32_000,
            maxOutputTokens: 4096,
            capabilitySource: 'test',
            estimatorFamily: 'default',
            safetyMarginTokens: 500,
        );
        $strategy = (new PromptSplitStrategyRegistry())->forHook('seeding.comment_generate');
        $plan = $preflight->planWithCapability($capability, $strategy, 'short seeding prompt', [
            'desired_output_tokens' => 512,
            'minimum_required_output_tokens' => 180,
            'continuation_already_inlined' => true,
            'schema_already_inlined' => true,
            'quantity' => 3,
            'count' => 3,
        ]);

        // Same lifecycle CanonicalAiTextExecutionService uses: register then require.
        $ref = new ReflectionClass(PromptBudgetPreflightService::class);
        $prop = $ref->getProperty('verifiedPlans');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $plans */
        $plans = $prop->getValue($preflight);
        $plans[$plan->planId] = $plan;
        $prop->setValue($preflight, $plans);

        self::assertNotSame('', $plan->planId);
        self::assertTrue($preflight->hasVerifiedPlan($plan->planId));
        $preflight->requireVerifiedPlanId($plan->planId);

        try {
            $preflight->requireVerifiedPlanId('');
            self::fail('Expected missing verified budget plan_id');
        } catch (PromptBudgetException $e) {
            self::assertStringContainsString('missing verified budget plan_id', $e->getMessage());
        }
    }
}
