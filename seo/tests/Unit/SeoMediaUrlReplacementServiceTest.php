<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Media\Services\SeoMediaUrlReplacementService;
use PHPUnit\Framework\TestCase;

final class SeoMediaUrlReplacementServiceTest extends TestCase
{
    public function test_build_variant_map_covers_relative_and_path(): void
    {
        $service = new SeoMediaUrlReplacementService();
        $map = $service->buildVariantMap(
            '/storage/uploads/seo_media/old-slug.jpg',
            '/storage/uploads/seo_media/new-slug.jpg',
        );

        $this->assertSame(
            '/storage/uploads/seo_media/new-slug.jpg',
            $map['/storage/uploads/seo_media/old-slug.jpg'] ?? null,
        );
        $this->assertSame(
            'uploads/seo_media/new-slug.jpg',
            $map['uploads/seo_media/old-slug.jpg'] ?? null,
        );
    }

    public function test_storage_path_from_url_ignores_cache_bust_query_and_hash(): void
    {
        $service = new SeoMediaUrlReplacementService();

        $this->assertSame(
            'uploads/seo_media/old-slug.jpg',
            $service->storagePathFromUrl('/storage/uploads/seo_media/old-slug.jpg?seo_reload=123#preview'),
        );
        $this->assertSame(
            'uploads/seo_media/old-slug.jpg',
            $service->storagePathFromUrl('https://seo.example.com/storage/uploads/seo_media/old-slug.jpg?seo_reload=123'),
        );
    }

    public function test_replace_in_text_updates_src_and_srcset_fragments(): void
    {
        $service = new SeoMediaUrlReplacementService();
        $html = '<img src="/storage/uploads/seo_media/old-slug.jpg" srcset="/storage/uploads/seo_media/old-slug.jpg 1x">';
        $next = $service->replaceInText($html, [
            '/storage/uploads/seo_media/old-slug.jpg' => '/storage/uploads/seo_media/new-slug.jpg',
        ]);

        $this->assertStringContainsString('/storage/uploads/seo_media/new-slug.jpg', $next);
        $this->assertStringNotContainsString('/storage/uploads/seo_media/old-slug.jpg', $next);
    }

    public function test_replace_in_text_updates_wordpress_sized_variants(): void
    {
        $service = new SeoMediaUrlReplacementService();
        $html = '<img src="https://example.com/wp-content/uploads/2026/05/old-slug.jpg" '
            .'srcset="https://example.com/wp-content/uploads/2026/05/old-slug-1024x768.jpg 1024w">';
        $next = $service->replaceInText($html, [
            'https://example.com/wp-content/uploads/2026/05/old-slug.jpg'
                => 'https://example.com/wp-content/uploads/2026/05/new-slug.jpg',
        ]);

        $this->assertStringContainsString('new-slug.jpg', $next);
        $this->assertStringContainsString('new-slug-1024x768.jpg', $next);
        $this->assertStringNotContainsString('old-slug.jpg', $next);
        $this->assertStringNotContainsString('old-slug-1024x768.jpg', $next);
    }

    public function test_find_remaining_old_refs(): void
    {
        $service = new SeoMediaUrlReplacementService();
        $remaining = $service->findRemainingOldRefs(
            '<p><img src="/storage/uploads/seo_media/old-slug.jpg"></p>',
            ['/storage/uploads/seo_media/old-slug.jpg' => '/storage/uploads/seo_media/new-slug.jpg'],
        );

        $this->assertSame(['/storage/uploads/seo_media/old-slug.jpg'], $remaining);
    }
}
