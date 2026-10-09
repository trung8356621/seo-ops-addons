<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\PromptRoutingPolicyResolver;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\Content\Services\CtaAutomation\CtaTextGenerator;
use PHPUnit\Framework\TestCase;

final class CtaFreeOnlyRoutingTest extends TestCase
{
    public function test_cta_generation_defaults_to_free_only_and_rejects_paid_routes(): void
    {
        self::assertSame(AiRoutingPolicy::FreeOnly, CtaTextGenerator::ROUTING_POLICY);
        self::assertFalse(CtaTextGenerator::ROUTING_POLICY->allowsPaidRoutes());
        self::assertTrue(AiRoutingPolicy::QuickFree->allowsPaidRoutes());
        self::assertSame(
            'Use free models only. Never fall back to paid models.',
            CtaTextGenerator::ROUTING_POLICY->description(),
        );
    }

    public function test_other_hook_defaults_stay_on_their_existing_policies(): void
    {
        $resolver = new PromptRoutingPolicyResolver();
        self::assertSame(AiRoutingPolicy::QuickFree, $resolver->hookDefault('seeding.comment.generate'));
        self::assertSame(AiRoutingPolicy::Normal, $resolver->hookDefault('article.cta.generate'));
        self::assertSame('quick_free', $resolver->hookMap()['seeding.comment.generate'] ?? null);
    }
}
