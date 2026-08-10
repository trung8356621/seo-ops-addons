<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\WordPressArticleTimestampService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class WordPressArticleTimestampServiceTest extends TestCase
{
    public function test_it_resolves_wordpress_post_dates(): void
    {
        $timestamps = (new WordPressArticleTimestampService)->resolve([
            'post_date' => '2020-02-27 10:19:00',
            'post_modified' => '2026-06-09 15:30:45',
        ]);

        self::assertSame('2020-02-27 10:19:00', $timestamps['created_at']->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-09 15:30:45', $timestamps['updated_at']->format('Y-m-d H:i:s'));
    }

    public function test_it_ignores_missing_or_zero_wordpress_dates(): void
    {
        $timestamps = (new WordPressArticleTimestampService)->resolve([
            'post_date' => '0000-00-00 00:00:00',
            'post_modified' => null,
        ]);

        self::assertSame([], $timestamps);
    }

    public function test_wordpress_linked_articles_do_not_generate_laravel_timestamps(): void
    {
        $article = (new SeoArticle)->forceFill([
            'wp_post_id' => 7331,
        ]);

        $article->updateTimestamps();
        $attributes = $article->getAttributes();

        self::assertArrayNotHasKey('created_at', $attributes);
        self::assertArrayNotHasKey('updated_at', $attributes);
    }

    public function test_remote_modified_is_newer_than_local(): void
    {
        $service = new WordPressArticleTimestampService;
        $local = Carbon::parse('2026-06-01 10:00:00');

        self::assertTrue($service->remoteIsNewerThanLocal($local, '2026-06-02 10:00:00'));
        self::assertFalse($service->remoteIsNewerThanLocal($local, '2026-06-01 10:00:00'));
        self::assertFalse($service->remoteIsNewerThanLocal($local, null));
    }
}
