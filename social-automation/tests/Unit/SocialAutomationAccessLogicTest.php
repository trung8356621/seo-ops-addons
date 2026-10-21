<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SocialAutomationAccessLogicTest extends TestCase
{
    public function test_slug_constant_in_resolver_source(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Support/SocialAutomationServiceResolver.php');

        self::assertStringContainsString("SLUG = 'social-automation'", $source);
        self::assertMatchesRegularExpression("/'is_active'\\s*=>\\s*false/", $source);
        self::assertDoesNotMatchRegularExpression("/'is_active'\\s*=>\\s*true/", $source);
    }
}
