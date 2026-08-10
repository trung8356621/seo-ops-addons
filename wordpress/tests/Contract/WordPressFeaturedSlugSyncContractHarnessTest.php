<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Contract;

use Omnichannel\Addons\WordPress\WpPluginContractMap;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract integration harness (not E2E): fake HTTP against wp-seo-ai REST shapes
 * for featured media + slug/sync surfaces used by the WordPress addon.
 */
final class WordPressFeaturedSlugSyncContractHarnessTest extends TestCase
{
    public function test_plugin_rest_namespace_matches_bridge_contract_map(): void
    {
        self::assertSame('omi-seo-ai/v1', WpPluginContractMap::PLUGIN_REST_NAMESPACE);
        $map = WpPluginContractMap::routeCapabilityMap();
        self::assertArrayHasKey('POST /posts/{id}/media', $map);
        self::assertArrayHasKey('POST /posts/{id}/editor-sync', $map);
        self::assertArrayHasKey('GET /sync/items', $map);
    }

    public function test_featured_media_post_contract_shape_with_http_fake(): void
    {
        $base = 'https://contract-harness.test';
        $postId = 456;
        $mediaUrl = $base.'/wp-json/'.WpPluginContractMap::PLUGIN_REST_NAMESPACE.'/posts/'.$postId.'/media';

        Http::fake([
            $mediaUrl => Http::response([
                'success' => true,
                'message' => 'featured updated',
                'featured_image_url' => $base.'/wp-content/uploads/2026/08/featured.jpg',
                'slug' => 'contract-slug',
            ], 200),
        ]);

        $response = Http::withToken('write-token')
            ->acceptJson()
            ->post($mediaUrl, [
                'featured_attachment_id' => 987,
            ]);

        self::assertTrue($response->successful());
        $json = $response->json();
        self::assertIsArray($json);
        self::assertTrue((bool) ($json['success'] ?? false));
        self::assertSame(
            $base.'/wp-content/uploads/2026/08/featured.jpg',
            (string) ($json['featured_image_url'] ?? ''),
        );

        Http::assertSent(static function ($request) use ($mediaUrl): bool {
            if ($request->url() !== $mediaUrl) {
                return false;
            }

            $data = $request->data();

            return (int) ($data['featured_attachment_id'] ?? 0) === 987;
        });
    }

    public function test_post_fetch_slug_sync_contract_shape_with_http_fake(): void
    {
        $base = 'https://contract-harness.test';
        $postId = 456;
        $postUrl = $base.'/wp-json/'.WpPluginContractMap::PLUGIN_REST_NAMESPACE.'/posts/'.$postId;

        Http::fake([
            $postUrl.'*' => Http::response([
                'success' => true,
                'post' => [
                    'wp_id' => $postId,
                    'type' => 'article',
                    'wp_post_type' => 'post',
                    'wp_entity' => 'post',
                    'title' => 'Contract Title',
                    'slug' => 'contract-title',
                    'permalink' => $base.'/contract-title/',
                    'post_content' => '<p>Body</p>',
                    'featured_image_url' => $base.'/wp-content/uploads/2026/08/featured.jpg',
                    'status' => 'publish',
                    'seo' => [
                        'seo_title' => 'Contract Title',
                        'meta_description' => 'Meta',
                        'focus_keyword' => 'keyword',
                    ],
                ],
            ], 200),
        ]);

        $response = Http::withToken('read-token')
            ->acceptJson()
            ->get($postUrl, ['_seo_fresh' => 1]);

        self::assertTrue($response->successful());
        $json = $response->json();
        self::assertIsArray($json);
        self::assertTrue((bool) ($json['success'] ?? false));

        $post = is_array($json['post'] ?? null) ? $json['post'] : [];
        self::assertSame($postId, (int) ($post['wp_id'] ?? 0));
        self::assertSame('contract-title', (string) ($post['slug'] ?? ''));
        self::assertSame('post', (string) ($post['wp_post_type'] ?? ''));
        self::assertNotSame('', (string) ($post['featured_image_url'] ?? ''));
        self::assertSame('Contract Title', (string) (($post['seo']['seo_title'] ?? '')));
    }

    public function test_sync_items_slug_and_featured_contract_shape_with_http_fake(): void
    {
        $base = 'https://contract-harness.test';
        $syncUrl = $base.'/wp-json/'.WpPluginContractMap::PLUGIN_REST_NAMESPACE.'/sync/items';

        Http::fake([
            $syncUrl.'*' => Http::response([
                'success' => true,
                'items' => [[
                    'wp_id' => 10,
                    'type' => 'article',
                    'wp_post_type' => 'post',
                    'wp_entity' => 'post',
                    'title' => 'Synced',
                    'slug' => 'synced-slug',
                    'permalink' => $base.'/synced-slug/',
                    'post_content' => '<p>Synced</p>',
                    'featured_image_url' => $base.'/featured-sync.jpg',
                    'status' => 'publish',
                ]],
            ], 200),
        ]);

        $response = Http::withToken('read-token')
            ->acceptJson()
            ->get($syncUrl, ['wp_id' => 10]);

        self::assertTrue($response->successful());
        $items = $response->json('items');
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertSame('synced-slug', (string) ($items[0]['slug'] ?? ''));
        self::assertSame($base.'/featured-sync.jpg', (string) ($items[0]['featured_image_url'] ?? ''));
    }
}
