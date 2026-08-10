<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Extension\ExtensionEventBus;
use Omnichannel\Addons\Agent\Extension\ExtensionEvents;
use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ExtensionCutoverCapabilityAndEventsTest extends TestCase
{
    public function test_canonical_capability_registry_exists_and_merges_extension_capabilities(): void
    {
        self::assertTrue(class_exists(CanonicalCapabilityRegistry::class));

        $source = (string) file_get_contents(
            (new ReflectionClass(CanonicalCapabilityRegistry::class))->getFileName(),
        );

        self::assertStringContainsString('ExtensionCapabilityRegistry', $source);
        self::assertStringContainsString('capability_conflict', $source);
        self::assertStringContainsString('function conflicts(', $source);
        self::assertStringContainsString('function isAgentWriteExposed(', $source);
    }

    public function test_canonical_registry_merges_and_reports_conflicts_without_laravel_boot(): void
    {
        $registry = new CanonicalCapabilityRegistry(
            new ContentProjectCapabilityRegistry,
            new ExtensionCapabilityRegistry,
            new ExtensionStateStore,
        );

        // No contributors registered — pure passthrough of core caps, no conflicts.
        self::assertNotEmpty($registry->all());
        self::assertSame([], $registry->conflicts());
        self::assertNotNull($registry->get('content_project.generate'));
        self::assertTrue($registry->isAgentWriteExposed('content_project.generate'));
        self::assertFalse($registry->isAgentWriteExposed('content_project.sync_items'));
        self::assertFalse($registry->isAgentWriteExposed('unknown.capability'));
    }

    public function test_agent_gateway_injects_canonical_registry_not_raw_extension_registry(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentGateway::class))->getFileName(),
        );

        self::assertStringContainsString('CanonicalCapabilityRegistry', $source);
        self::assertStringNotContainsString('ExtensionCapabilityRegistry', $source);
        self::assertStringNotContainsString('use App\\Addons\\SeoContentAi\\Services\\ContentProject\\Application\\Capabilities\\ContentProjectCapabilityRegistry;', $source);
    }

    public function test_domain_events_bridge_uses_event_envelope(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectDomainEvents::class))->getFileName(),
        );

        self::assertStringContainsString('ExtensionEventEnvelope', $source);
        self::assertStringContainsString('ExtensionEventEnvelope::make', $source);
    }

    public function test_extension_events_constants_are_versioned(): void
    {
        self::assertStringEndsWith('.v1', ExtensionEvents::PROJECT_CREATED);
        self::assertStringEndsWith('.v1', ExtensionEvents::ITEMS_GENERATED);
        self::assertStringEndsWith('.v1', ExtensionEvents::PUBLISHED);
        self::assertStringEndsWith('.v1', ExtensionEvents::ARCHIVED);
    }

    public function test_extension_event_bus_has_try_catch_and_isolates_each_listener(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ExtensionEventBus::class))->getFileName(),
        );

        self::assertStringContainsString('try {', $source);
        self::assertStringContainsString('catch (Throwable', $source);

        $bus = new ExtensionEventBus();
        $calls = [];

        $bus->subscribe('demo.event', static function (): void {
            throw new RuntimeException('boom');
        });
        $bus->subscribe('demo.event', static function (array $payload) use (&$calls): void {
            $calls[] = $payload;
        });

        $bus->dispatch('demo.event', ['ok' => true]);

        self::assertSame([['ok' => true]], $calls);
    }
}
