<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Support\WordPressImageUrl;
use PHPUnit\Framework\TestCase;

final class WordPressImageUrlTest extends TestCase
{
    public function test_to_full_size_strips_wordpress_dimensions(): void
    {
        $url = 'https://example.com/wp-content/uploads/2026/05/cap-hoc-sinh-1-480x393.jpg';

        $this->assertSame(
            'https://example.com/wp-content/uploads/2026/05/cap-hoc-sinh-1.jpg',
            WordPressImageUrl::toFullSize($url),
        );
    }

    public function test_to_full_size_keeps_local_media_unchanged(): void
    {
        $url = '/storage/uploads/seo_media/site-1/foo-480x393.webp';

        $this->assertSame($url, WordPressImageUrl::toFullSize($url));
    }

    public function test_slug_from_url_uses_full_basename(): void
    {
        $this->assertSame(
            'cap-hoc-sinh-1',
            WordPressImageUrl::slugFromUrl('https://example.com/wp-content/uploads/cap-hoc-sinh-1-300x300.jpg'),
        );
    }
}
