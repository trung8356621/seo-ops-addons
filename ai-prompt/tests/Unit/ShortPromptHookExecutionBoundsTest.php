<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\AiRoutingContextResolver;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionTransport;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\AiPrompt\Support\ShortPromptHookExecutionBounds;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ShortPromptHookExecutionBoundsTest extends TestCase
{
    public function test_meta_description_is_short_hook(): void
    {
        $this->assertTrue(ShortPromptHookExecutionBounds::matches('article.meta_description_suggestion'));
        $this->assertSame(
            ['http_timeout_seconds' => 70],
            ShortPromptHookExecutionBounds::providerOptions('article.meta_description_suggestion'),
        );
        $this->assertSame(100, ShortPromptHookExecutionBounds::TOTAL_WALL_CLOCK_SECONDS);
        $deadline = ShortPromptHookExecutionBounds::wallClockDeadline(
            'article.meta_description_suggestion',
            1_000.0,
        );
        $this->assertSame(1_100.0, $deadline);
        $this->assertNull(ShortPromptHookExecutionBounds::wallClockDeadline('article.content.generate', 1_000.0));
    }

    public function test_provider_options_cap_http_timeout_to_remaining_wall_budget(): void
    {
        ShortPromptHookExecutionBounds::clearDeadline();
        ShortPromptHookExecutionBounds::bindDeadline(microtime(true) + 12.5);
        try {
            $opts = ShortPromptHookExecutionBounds::providerOptions('article.meta_description_suggestion');
            $this->assertSame(12, $opts['http_timeout_seconds']);
        } finally {
            ShortPromptHookExecutionBounds::clearDeadline();
        }

        ShortPromptHookExecutionBounds::bindDeadline(microtime(true) + 90.0);
        try {
            $opts = ShortPromptHookExecutionBounds::providerOptions('article.meta_description_suggestion');
            $this->assertSame(70, $opts['http_timeout_seconds']);
        } finally {
            ShortPromptHookExecutionBounds::clearDeadline();
        }
    }

    public function test_long_form_article_generation_is_excluded(): void
    {
        $this->assertFalse(ShortPromptHookExecutionBounds::matches('article.content.generate'));
        $this->assertSame([], ShortPromptHookExecutionBounds::providerOptions('article.content.generate'));
    }

    public function test_adapter_default_timeout_remains_180(): void
    {
        $this->assertSame(180, OpenAiCompatibleProtocolAdapter::DEFAULT_TEXT_TIMEOUT_SECONDS);

        $src = file_get_contents((string) (new ReflectionClass(OpenAiCompatibleProtocolAdapter::class))->getFileName()) ?: '';
        $this->assertStringContainsString('http_timeout_seconds', $src);
        $this->assertStringContainsString('DEFAULT_TEXT_TIMEOUT_SECONDS', $src);
    }

    public function test_routing_context_caps_short_hook_attempts_on_background_transport(): void
    {
        $context = new AiRoutingContext(
            userId: 1,
            hookKey: 'article.meta_description_suggestion',
            routingPolicy: AiRoutingPolicy::Normal,
            executionTransport: AiExecutionTransport::Background,
        );

        $enriched = (new AiRoutingContextResolver)->enrich($context, 6, 3);

        $this->assertSame(3, $enriched->maxAiAttempts);
        $this->assertSame(1, $enriched->maxFreeAttempts);
    }

    public function test_long_form_keeps_resilience_attempt_budget(): void
    {
        $context = new AiRoutingContext(
            userId: 1,
            hookKey: 'article.content.generate',
            routingPolicy: AiRoutingPolicy::Normal,
            executionTransport: AiExecutionTransport::Background,
        );

        $enriched = (new AiRoutingContextResolver)->enrich($context, 6, 3);

        $this->assertSame(6, $enriched->maxAiAttempts);
        $this->assertSame(3, $enriched->maxFreeAttempts);
    }
}
