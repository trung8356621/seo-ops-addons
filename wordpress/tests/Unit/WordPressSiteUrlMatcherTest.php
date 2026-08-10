<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Support\WordPressSiteUrlMatcher;
use App\Models\Site;
use Tests\TestCase;

final class WordPressSiteUrlMatcherTest extends TestCase
{
    public function test_it_matches_wordpress_upload_url_for_site_domain(): void
    {
        $matcher = new WordPressSiteUrlMatcher;
        $site = new Site(['domain' => 'baloquatang.net']);

        $this->assertTrue($matcher->siteUrlMatchesSite(
            $site,
            'https://baloquatang.net/wp-content/uploads/2022/01/phoi-ao-tre-vai.jpg',
        ));

        $this->assertTrue($matcher->siteUrlMatchesSite(
            $site,
            'https://www.baloquatang.net/wp-content/uploads/2022/01/phoi-ao-tre-vai.jpg',
        ));
    }

    public function test_it_rejects_external_image_hosts(): void
    {
        $matcher = new WordPressSiteUrlMatcher;
        $site = new Site(['domain' => 'baloquatang.net']);

        $this->assertFalse($matcher->siteUrlMatchesSite(
            $site,
            'https://images.unsplash.com/photo-example.jpg',
        ));
    }
}
