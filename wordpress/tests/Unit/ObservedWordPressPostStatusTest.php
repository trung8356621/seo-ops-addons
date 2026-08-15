<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Support\ObservedWordPressPostStatus;
use PHPUnit\Framework\TestCase;

final class ObservedWordPressPostStatusTest extends TestCase
{
    public function test_desired_and_observed_are_disjoint(): void
    {
        $desired = ['waiting', 'scheduled', 'dispatching', 'processing', 'retrying', 'completed', 'failed', 'cancelled'];
        foreach ($desired as $status) {
            self::assertFalse(in_array($status, ObservedWordPressPostStatus::values(), true), $status);
        }
    }

    public function test_publish_is_live(): void
    {
        self::assertTrue(ObservedWordPressPostStatus::isLiveOnSite(ObservedWordPressPostStatus::PUBLISH));
        self::assertFalse(ObservedWordPressPostStatus::isLiveOnSite(ObservedWordPressPostStatus::MISSING));
    }
}
