<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Services\SeedingTopicService;
use PHPUnit\Framework\TestCase;

/**
 * Legacy site-scoped service retired — keep class for autoload, assert retirement.
 */
final class SeedingTopicServiceTest extends TestCase
{
    public function test_legacy_service_is_retired(): void
    {
        $service = new SeedingTopicService;
        $this->expectException(\RuntimeException::class);
        $service->create([]);
    }
}
