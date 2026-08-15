<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Console\PollWordPressHeartbeatCommand;
use Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WordPressHeartbeatIndependentPollTest extends TestCase
{
    public function test_heartbeat_poll_is_not_site_sync_only(): void
    {
        self::assertTrue(class_exists(PollWordPressHeartbeatCommand::class));
        self::assertTrue(class_exists(WordPressHeartbeatPollService::class));
        $src = (string) file_get_contents(
            (new ReflectionClass(WordPressSiteSyncClient::class))->getFileName(),
        );
        self::assertStringContainsString('/wp-json/omi-seo-ai/v1/heartbeat', $src);
        self::assertStringContainsString('fetchLinkAnalysisBatch', $src);
        self::assertStringContainsString('link-analysis/batch', $src);
        $observe = (string) file_get_contents(
            (new ReflectionClass(\Omnichannel\Addons\WordPress\Services\WordPressObservedStateClient::class))->getFileName(),
        );
        self::assertStringContainsString('/posts/', $observe);
        self::assertStringContainsString('/observe', $observe);
        self::assertStringContainsString('Lightweight WP post observe', $observe);
    }
}
