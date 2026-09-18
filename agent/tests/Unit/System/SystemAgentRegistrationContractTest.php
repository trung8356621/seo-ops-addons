<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit\System;

use PHPUnit\Framework\TestCase;

final class SystemAgentRegistrationContractTest extends TestCase
{
    public function test_agent_registers_echo_capability_without_content_project(): void
    {
        $echo = dirname(__DIR__, 3).'/src/System/AgentEchoCapabilityHandler.php';
        $provider = dirname(__DIR__, 3).'/src/AgentServiceProvider.php';
        self::assertFileExists($echo);
        $echoSource = (string) file_get_contents($echo);
        $providerSource = (string) file_get_contents($provider);

        self::assertStringContainsString('content_project_required', $echoSource);
        self::assertStringNotContainsString('ContentProjectAgentGateway', $echoSource);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\ContentProjects\\', $echoSource);
        self::assertStringContainsString('AgentEchoCapabilityHandler', $providerSource);
        self::assertStringContainsString('SystemCapabilityRegistry', $providerSource);
        self::assertStringContainsString('SystemAgentSkillRegistry', $providerSource);
    }
}
