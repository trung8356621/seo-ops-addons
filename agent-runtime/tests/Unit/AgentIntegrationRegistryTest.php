<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Integration\AgentIntegrationRegistry;
use Omnichannel\Addons\AgentRuntime\Integration\AgentOperationHandlerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentIntegrationRegistryTest extends TestCase
{
    public function test_synthetic_service_registers_cases_and_dispatch_requires_explicit_handler(): void
    {
        $base = dirname(__DIR__).'/Fixtures/demo/agent';
        $registry = new AgentIntegrationRegistry();
        $registry->register('demo', $base.'/routing.json', $base.'/cases.json');

        self::assertArrayHasKey('demo', $registry->document()['modules']);
        self::assertSame('demo.read', $registry->cases('demo')[0]['expected_operation']);

        $handlers = new AgentOperationHandlerRegistry();
        $this->expectException(RuntimeException::class);
        $handlers->dispatch('site.knowledge');
    }

    public function test_synthetic_handler_dispatches_without_core_module_branch(): void
    {
        $handlers = new AgentOperationHandlerRegistry();
        $handlers->register('site.knowledge', static fn (): string => 'demo-ok');
        self::assertSame('demo-ok', $handlers->dispatch('site.knowledge'));
    }

    public function test_duplicate_service_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $registry = new AgentIntegrationRegistry();
        $registry->register('demo', 'a', 'b');
        $registry->register('demo', 'a', 'b');
    }
}
