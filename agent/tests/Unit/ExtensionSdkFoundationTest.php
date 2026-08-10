<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Extension\ExtensionCompatibilityChecker;
use Omnichannel\Addons\Agent\Extension\ExtensionDiscovery;
use Omnichannel\Addons\Agent\Extension\ExtensionEventBus;
use Omnichannel\Addons\Agent\Extension\ExtensionManifest;
use Omnichannel\Addons\Agent\Extension\Registry\ContentPlatformRegistry;
use Omnichannel\Addons\Publishing\Extension\Registry\PublisherRegistry;
use Omnichannel\Addons\Agent\Extension\SdkVersion;
use Omnichannel\Addons\WordPress\Extension\WordpressExtensionProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExtensionSdkFoundationTest extends TestCase
{
    public function test_sdk_version_supports_current_major(): void
    {
        self::assertTrue(SdkVersion::supports(SdkVersion::MAJOR));
        self::assertFalse(SdkVersion::supports(99));
    }

    public function test_manifest_parses_plugin_json_shape(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'id' => 'demo',
            'name' => 'Demo',
            'version' => '1.2.3',
            'sdk' => 1,
            'provider' => 'Demo\\Provider',
            'providers' => ['publisher'],
            'capabilities' => [],
            'requires' => [],
        ]);

        self::assertSame('demo', $manifest->id);
        self::assertSame('Demo', $manifest->name);
        self::assertSame('1.2.3', $manifest->version);
        self::assertSame(1, $manifest->sdk);
        self::assertSame('Demo\\Provider', $manifest->provider);
        self::assertSame(['publisher'], $manifest->providers);
    }

    public function test_compatibility_rejects_sdk_99(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'id' => 'future',
            'name' => 'Future',
            'version' => '9.0.0',
            'sdk' => 99,
            'provider' => 'Future\\Provider',
        ]);

        $result = (new ExtensionCompatibilityChecker())->check($manifest);

        self::assertFalse($result['compatible']);
        self::assertNotEmpty($result['reasons']);
        self::assertTrue($result['migration_needed']);
    }

    public function test_wordpress_plugin_json_and_provider_exist(): void
    {
        $pluginJson = ProjectRoot::addonsPath().'/wordpress/src/Extension/plugin.json';
        self::assertFileExists($pluginJson);

        $manifest = ExtensionManifest::fromFile($pluginJson);
        self::assertSame('wordpress', $manifest->id);
        self::assertTrue(class_exists(WordpressExtensionProvider::class));
    }

    public function test_publisher_registry_registers_fake_driver(): void
    {
        $registry = new PublisherRegistry();
        $driver = new class implements \Omnichannel\Addons\Publishing\Extension\Contracts\PublisherDriver
        {
            public function id(): string
            {
                return 'fake';
            }

            public function label(): string
            {
                return 'Fake';
            }

            public function publish(array $payload): array
            {
                return ['success' => true];
            }

            public function update(array $payload): array
            {
                return ['success' => false];
            }

            public function delete(array $payload): array
            {
                return ['success' => false];
            }

            public function find(array $query): ?array
            {
                return null;
            }

            public function health(): array
            {
                return ['ok' => true, 'message' => 'ok'];
            }
        };

        $registry->register('fake', $driver);

        self::assertTrue($registry->has('fake'));
        self::assertSame('fake', $registry->get('fake')?->id());
    }

    public function test_content_platform_registry_class_exists(): void
    {
        self::assertTrue(class_exists(ContentPlatformRegistry::class));
    }

    public function test_extension_event_bus_dispatch_and_subscribe(): void
    {
        $bus = new ExtensionEventBus();
        $seen = [];

        $bus->subscribe('demo.event', static function (array $payload) use (&$seen): void {
            $seen[] = $payload;
        });

        $bus->dispatch('demo.event', ['id' => 1]);

        self::assertSame([['id' => 1]], $seen);
    }

    public function test_extension_discovery_does_not_eval_or_include_arbitrary_code(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ExtensionDiscovery::class))->getFileName(),
        );

        self::assertStringNotContainsString('eval(', $source);
        self::assertStringNotContainsString('include ', $source);
        self::assertStringNotContainsString('require ', $source);
        self::assertStringContainsString('class_exists', $source);
        self::assertStringContainsString('->make(', $source);
    }

    public function test_extension_docs_files_soft_skip_if_missing(): void
    {
        $roots = [
            ProjectRoot::path().DIRECTORY_SEPARATOR.'docs',
            getcwd().DIRECTORY_SEPARATOR.'docs',
        ];

        $names = [
            'EXTENSION_SDK.md',
            'PUBLISHER_SDK.md',
            'AI_PROVIDER_SDK.md',
            'CAPABILITY_SDK.md',
            'PIPELINE_SDK.md',
        ];

        $foundAny = false;
        foreach ($roots as $root) {
            foreach ($names as $name) {
                $path = $root.DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) {
                    $foundAny = true;
                    $body = (string) file_get_contents($path);
                    self::assertStringContainsString('SDK', $body);
                }
            }
        }

        if (! $foundAny) {
            self::markTestSkipped('Extension SDK docs not present on this host');
        }
    }
}
