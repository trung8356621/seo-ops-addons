<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

final class WordPressArticleSyncStatusTest extends TestCase
{
    public function test_outbound_sync_always_publishes_even_when_laravel_scheduled(): void
    {
        $article = new SeoArticle([
            'status' => 'scheduled',
            'wp_post_id' => 888,
            'published_at' => now()->addDay(),
        ]);

        $service = app(WordPressArticleSyncService::class);
        $method = new ReflectionMethod($service, 'resolveWordPressStatusPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $article);

        $this->assertSame('publish', $payload['status']);
        $this->assertNotEmpty($payload['post_date'] ?? null);
        // post_date tương lai bị clamp — không đẩy WP thành future.
        $this->assertTrue(
            Carbon::parse((string) $payload['post_date'])->lessThanOrEqualTo(now()->addMinute()),
        );
    }

    public function test_published_status_maps_to_wordpress_publish(): void
    {
        $article = new SeoArticle([
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        $service = app(WordPressArticleSyncService::class);
        $method = new ReflectionMethod($service, 'resolveWordPressStatusPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $article);

        $this->assertSame('publish', $payload['status']);
        $this->assertNotEmpty($payload['post_date'] ?? null);
    }

    public function test_draft_with_existing_wp_post_still_publishes_on_sync(): void
    {
        $article = new SeoArticle([
            'status' => 'draft',
            'wp_post_id' => 55,
            'published_at' => now()->subHour(),
        ]);

        $service = app(WordPressArticleSyncService::class);
        $method = new ReflectionMethod($service, 'resolveWordPressStatusPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $article);

        $this->assertSame('publish', $payload['status']);
        $this->assertNotEmpty($payload['post_date'] ?? null);
    }

    public function test_trash_status_omits_wordpress_status_payload(): void
    {
        $article = new SeoArticle([
            'status' => 'trash',
            'wp_post_id' => 55,
        ]);

        $service = app(WordPressArticleSyncService::class);
        $method = new ReflectionMethod($service, 'resolveWordPressStatusPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, $article);

        $this->assertSame([], $payload);
    }

    public function test_publish_for_article_entry_point_exists(): void
    {
        $this->assertTrue(method_exists(WordPressArticleSyncService::class, 'publishForArticle'));
    }

    public function test_should_not_skip_editor_sync_when_forcing_publish_status(): void
    {
        $article = new SeoArticle([
            'id' => 4092,
            'wp_post_id' => 11391,
            'title' => 'Demo title',
            'slug' => 'demo-title',
            'status' => 'published',
        ]);

        $service = app(WordPressArticleSyncService::class);
        $fingerprintMethod = new ReflectionMethod($service, 'editorSyncFingerprint');
        $fingerprintMethod->setAccessible(true);

        $prepared = [
            'request_payload' => [
                'title' => 'Demo title',
                'slug' => 'demo-title',
                'status' => 'publish',
                'post_type' => 'post',
                'seo' => [],
            ],
            'post_content' => '<p>Hello world</p>',
            'faqs' => [],
            'local_media_sync_errors' => [],
        ];

        $fingerprint = $fingerprintMethod->invoke($service, $prepared);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => WordPressArticleSyncService::META_WP_EDITOR_SYNC_FINGERPRINT,
                'meta_value' => $fingerprint,
            ]),
        ]));

        $result = $service->shouldSkipEditorSyncRequest($article, $prepared);

        $this->assertFalse($result['skip']);
        $this->assertSame('force_status_override', $result['reason']);
    }

    public function test_should_not_skip_editor_sync_when_local_edit_pending(): void
    {
        $article = new SeoArticle([
            'id' => 1,
            'wp_post_id' => 99,
        ]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => ArticleWordPressSyncFlagService::META_LOCAL_EDIT_PENDING,
                'meta_value' => '1',
            ]),
        ]));

        $service = app(WordPressArticleSyncService::class);
        $result = $service->shouldSkipEditorSyncRequest($article, [
            'request_payload' => ['title' => 'A'],
            'post_content' => '',
            'faqs' => [],
            'local_media_sync_errors' => [],
        ]);

        $this->assertFalse($result['skip']);
        $this->assertSame('local_edit_pending', $result['reason']);
    }

    public function test_should_not_skip_editor_sync_when_post_content_has_local_seo_media(): void
    {
        $article = new SeoArticle([
            'id' => 1,
            'wp_post_id' => 99,
        ]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => WordPressArticleSyncService::META_WP_EDITOR_SYNC_FINGERPRINT,
                'meta_value' => hash('sha256', 'same-fingerprint'),
            ]),
        ]));

        $service = app(WordPressArticleSyncService::class);
        $prepared = [
            'request_payload' => ['title' => 'A'],
            'post_content' => '<p><img src="/storage/uploads/seo_media/new-image.webp" alt=""></p>',
            'faqs' => [],
            'local_media_sync_errors' => [],
        ];

        $result = $service->shouldSkipEditorSyncRequest($article, $prepared);

        $this->assertFalse($result['skip']);
        $this->assertSame('pending_local_media', $result['reason']);
    }

    public function test_create_for_article_accepts_optional_editor_payload(): void
    {
        $method = new ReflectionMethod(WordPressArticleSyncService::class, 'createForArticle');
        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('editorPayload', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->allowsNull());
    }
}
