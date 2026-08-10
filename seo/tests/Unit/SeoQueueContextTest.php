<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;
use Tests\TestCase;

final class SeoQueueContextTest extends TestCase
{
    public function test_wp_sync_queue_context_bypasses_content_manager_block_without_auth(): void
    {
        Auth::logout();

        $service = app(WordPressArticleSyncService::class);
        $method = new ReflectionMethod(WordPressArticleSyncService::class, 'blockContentManagerWordPressSync');
        $method->setAccessible(true);

        $blocked = $method->invoke($service);
        $this->assertIsArray($blocked);
        $this->assertFalse($blocked['success'] ?? true);
        $this->assertSame('WORDPRESS_SYNC_FORBIDDEN_ROLE', $blocked['error_code'] ?? null);

        SeoQueueContext::runWpSyncFromQueue(function () use ($method, $service): void {
            $this->assertNull($method->invoke($service));
        });
    }
}
