<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Omnichannel\Addons\AgentRuntime\Routing\SemanticRoutingConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SemanticRoutingConfigTest extends TestCase
{
    public function test_upgrade_repairs_only_corrupted_system_strings_and_preserves_customizations(): void
    {
        $config = new SemanticRoutingConfig();
        $defaults = $config->defaults();
        $saved = $defaults;
        $saved['global'][0]['examples'] = [
            "Website dang theo d\u{FFFD}i nh?ng t? kh\u{FFFD}a SEO n\u{FFFD}o?",
            'My valid custom inventory example.',
        ];
        $saved['global'][0]['targets'][0]['weight'] = 7;
        $saved['global'][0]['enabled'] = false;
        $saved['lexical_hints'][0]['phrases'][0] = "d\u{FFFD} bao ph?";
        array_splice($saved['global'], 2, 1);

        $method = new ReflectionMethod($config, 'upgradePersisted');
        $upgraded = $method->invoke($config, $saved, $defaults);

        self::assertSame($defaults['global'][0]['examples'][0], $upgraded['global'][0]['examples'][0]);
        self::assertSame('My valid custom inventory example.', $upgraded['global'][0]['examples'][1]);
        self::assertSame(7, $upgraded['global'][0]['targets'][0]['weight']);
        self::assertFalse($upgraded['global'][0]['enabled']);
        self::assertSame($defaults['lexical_hints'][0]['phrases'][0], $upgraded['lexical_hints'][0]['phrases'][0]);
        self::assertContains('topic_coverage_read', array_column($upgraded['global'], 'id'));
    }
}
