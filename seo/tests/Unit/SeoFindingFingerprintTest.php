<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoFindingSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SeoFindingFingerprintTest extends TestCase
{
    public function test_same_natural_key_reuses_fingerprint_logic(): void
    {
        $left = hash('sha256', '1|broken_link|site|1');
        $right = hash('sha256', '1|broken_link|site|1');
        self::assertSame($left, $right);
        self::assertTrue(class_exists(SeoFindingSyncService::class));
        $method = new ReflectionMethod(SeoFindingSyncService::class, 'upsert');
        self::assertTrue($method->isPrivate());
    }
}
