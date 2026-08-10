<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Feature;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArticleOutlineApiAccessTest extends TestCase
{
    public function test_content_manager_can_load_outline_with_tenant_headers(): void
    {
        $user = User::query()->whereKey(3)->first();
        if (! $user instanceof User) {
            $this->markTestSkipped('User #3 not found in database.');
        }

        $response = $this->actingAs($user)->getJson('/api/seo/articles/4190/outline', [
            'X-Site-ID' => '7',
            'X-SEO-Connection' => 'YTzrG3WKuG3ygjB3cA8wU7CVfV9aRh7G',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_content_manager_can_import_url_with_tenant_headers(): void
    {
        $user = User::query()->whereKey(3)->first();
        if (! $user instanceof User) {
            $this->markTestSkipped('User #3 not found in database.');
        }

        $response = $this->actingAs($user)->postJson('/api/seo/media/import-url', [
            'url' => 'https://baloquatang.net/wp-content/uploads/2022/01/Phoi-ao-tre-vai-voi-quan-jeans-rach.jpg',
            'article_id' => 4190,
            'site_id' => 7,
        ], [
            'X-Site-ID' => '7',
            'X-SEO-Connection' => 'YTzrG3WKuG3ygjB3cA8wU7CVfV9aRh7G',
        ]);

        if ($response->status() === 403) {
            $this->fail('403: '.$response->getContent());
        }

        $this->assertContains($response->status(), [200, 422]);
    }
}
