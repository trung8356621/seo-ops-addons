<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\WordPress\Support\ObservedWordPressPostStatus;
use PHPUnit\Framework\TestCase;

final class ObservedVsDesiredPublishSemanticsTest extends TestCase
{
    public function test_failed_laravel_and_wp_publish_are_different_layers(): void
    {
        $desired = ContentProjectPublishQueueStatus::Failed->value;
        $observed = ObservedWordPressPostStatus::PUBLISH;
        self::assertSame('failed', $desired);
        self::assertSame('publish', $observed);
        self::assertNotSame($desired, $observed);
        self::assertTrue(ObservedWordPressPostStatus::isLiveOnSite($observed));
    }
}
