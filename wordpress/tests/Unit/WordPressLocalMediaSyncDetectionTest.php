<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Services\WordPressLocalMediaSyncService;
use PHPUnit\Framework\TestCase;

final class WordPressLocalMediaSyncDetectionTest extends TestCase
{
    public function test_html_contains_local_seo_media_detects_storage_uploads_path(): void
    {
        $service = new WordPressLocalMediaSyncService();

        $html = '<figure><img src="https://seo.example.com/storage/uploads/seo_media/demo-1.webp" alt=""></figure>';

        $this->assertTrue($service->htmlContainsLocalSeoMedia($html));
    }

    public function test_html_contains_local_seo_media_returns_false_for_wordpress_urls(): void
    {
        $service = new WordPressLocalMediaSyncService();

        $html = '<figure><img src="https://congtybalo.com/wp-content/uploads/2026/07/demo.webp" alt=""></figure>';

        $this->assertFalse($service->htmlContainsLocalSeoMedia($html));
    }
}
