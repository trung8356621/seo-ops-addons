<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentModelRoutingContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\RegistryAgentModelRouter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentModelRouterTest extends TestCase
{
    public function test_healthy_allowed_model_selected(): void
    {
        $router = new RegistryAgentModelRouter(staticCandidates: [
            [
                'provider_key' => 'mock_provider',
                'model' => 'mock-model-1',
                'connection_id' => 9,
                'context_limit_tokens' => 8000,
                'supports_structured_output' => true,
                'enabled' => true,
            ],
        ]);

        $selection = $router->resolve(new AgentModelRoutingContext(
            taskType: 'plan_generation',
            requiresStructuredOutput: true,
            connectionId: 9,
        ));

        self::assertSame('mock_provider', $selection->providerKey);
        self::assertSame('mock-model-1', $selection->model);
        self::assertStringContainsString('plan_generation', $selection->routingReason);
    }

    public function test_disabled_model_rejected(): void
    {
        $router = new RegistryAgentModelRouter(staticCandidates: [
            [
                'provider_key' => 'mock_provider',
                'model' => 'dead',
                'enabled' => false,
                'supports_structured_output' => true,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('model_unavailable');
        $router->resolve(new AgentModelRoutingContext(taskType: 'plan_generation'));
    }

    public function test_unsupported_structured_mode_rejected(): void
    {
        $router = new RegistryAgentModelRouter(staticCandidates: [
            [
                'provider_key' => 'mock_provider',
                'model' => 'plain',
                'supports_structured_output' => false,
                'enabled' => true,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $router->resolve(new AgentModelRoutingContext(
            taskType: 'plan_generation',
            requiresStructuredOutput: true,
        ));
    }

    public function test_user_selection_respected_when_valid(): void
    {
        $router = new RegistryAgentModelRouter(staticCandidates: [
            [
                'provider_key' => 'p1',
                'model' => 'a',
                'enabled' => true,
                'supports_structured_output' => true,
            ],
            [
                'provider_key' => 'p2',
                'model' => 'b',
                'enabled' => true,
                'supports_structured_output' => true,
            ],
        ]);

        $selection = $router->resolve(new AgentModelRoutingContext(
            taskType: 'assistant_answer',
            userSelectedModel: 'b',
        ));

        self::assertSame('b', $selection->model);
        self::assertSame('user_selected', $selection->routingReason);
        self::assertFalse($selection->fallbackUsed);
    }

    public function test_router_source_has_no_vendor_hardcode_branches(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Services/AgentWorkspace/Planning/Services/RegistryAgentModelRouter.php',
        );

        self::assertStringNotContainsString("=== 'gemini'", $source);
        self::assertStringNotContainsString('=== "gemini"', $source);
        self::assertStringNotContainsString("provider === 'claude'", $source);
    }
}
