<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Jobs\SyncArticleToWordPressFromQueueJob;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ArticleWpSyncQueueServiceTest extends TestCase
{
    public function test_enqueue_rejects_when_already_pending(): void
    {
        Bus::fake();

        $article = new SeoArticle(['id' => 7, 'site_id' => 0, 'status' => 'draft']);
        $article->wp_sync_queue_meta = json_encode(['status' => ArticleWpSyncQueueService::STATUS_PENDING]);

        $service = app(ArticleWpSyncQueueService::class);
        $result = $service->enqueueFromEditorBundle($article, ['html' => '<p>x</p>']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('WordPressManualSyncService', (string) ($result['message'] ?? ''));
        Bus::assertNothingDispatched();
    }

    public function test_enqueue_without_manual_context_is_rejected_even_when_idle(): void
    {
        Bus::fake();

        $article = new SeoArticle(['id' => 8, 'site_id' => 0, 'status' => 'draft']);
        $article->wp_sync_queue_meta = json_encode(['status' => ArticleWpSyncQueueService::STATUS_COMPLETED]);

        $result = app(ArticleWpSyncQueueService::class)->enqueueFromEditorBundle($article, ['html' => '<p>x</p>']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('WordPressManualSyncService', (string) ($result['message'] ?? ''));
        Bus::assertNothingDispatched();
    }

    public function test_read_queue_meta_uses_subquery_column_when_relation_whitelisted(): void
    {
        $article = new SeoArticle(['id' => 21]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => 'seo_focus_keyword',
                'meta_value' => 'keyword',
            ]),
        ]));
        $article->wp_sync_queue_meta = json_encode([
            'status' => ArticleWpSyncQueueService::STATUS_FAILED,
            'error' => 'WP timeout',
        ]);

        $payload = app(ArticleWpSyncQueueService::class)->readQueueMeta($article);

        $this->assertSame(ArticleWpSyncQueueService::STATUS_FAILED, $payload['status'] ?? null);
        $this->assertSame('WP timeout', $payload['error'] ?? null);
    }

    public function test_read_queue_meta_decodes_json_payload(): void
    {
        $article = new SeoArticle(['id' => 9]);
        $article->wp_sync_queue_meta = json_encode([
            'status' => ArticleWpSyncQueueService::STATUS_FAILED,
            'error' => 'WP timeout',
        ]);

        $payload = app(ArticleWpSyncQueueService::class)->readQueueMeta($article);

        $this->assertSame(ArticleWpSyncQueueService::STATUS_FAILED, $payload['status'] ?? null);
        $this->assertSame('WP timeout', $payload['error'] ?? null);
    }

    public function test_queue_status_label_maps_known_status(): void
    {
        $article = new SeoArticle(['id' => 11]);
        $article->wp_sync_queue_meta = json_encode(['status' => ArticleWpSyncQueueService::STATUS_PROCESSING]);

        $label = app(ArticleWpSyncQueueService::class)->queueStatusLabel($article);

        $this->assertNotNull($label);
        $this->assertNotSame('', $label);
    }

    public function test_resync_rejects_completed_status(): void
    {
        Bus::fake();

        $article = new SeoArticle(['id' => 15, 'site_id' => 0]);
        $article->wp_sync_queue_meta = json_encode(['status' => ArticleWpSyncQueueService::STATUS_COMPLETED]);
        $article->wp_sync_queue_bundle = json_encode(['html' => '<p>queued</p>']);

        $result = app(ArticleWpSyncQueueService::class)->resync($article);

        $this->assertFalse($result['success']);
        Bus::assertNothingDispatched();
    }

    public function test_dispatch_wp_sync_job_is_blocked(): void
    {
        Bus::fake();

        $job = new SyncArticleToWordPressFromQueueJob(42);
        $this->assertSame(ArticleWpSyncQueueService::QUEUE_NAME, $job->queue);

        $method = new \ReflectionMethod(ArticleWpSyncQueueService::class, 'dispatchWpSyncJob');
        $method->setAccessible(true);
        $ok = $method->invoke(app(ArticleWpSyncQueueService::class), 42);

        $this->assertFalse($ok);
        Bus::assertNothingDispatched();
    }

    public function test_prepare_bundle_for_immediate_sync_publishes_now(): void
    {
        $bundle = app(ArticleWpSyncQueueService::class)->prepareBundleForImmediateSync([
            'html' => '<p>x</p>',
            'publish_box' => [
                'publish_immediately' => true,
                'status' => 'scheduled',
                'publish_day' => '01',
                'publish_month' => '01',
                'publish_year' => '2099',
                'publish_hour' => '23',
                'publish_minute' => '59',
            ],
        ]);

        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $this->assertSame('published', $publishBox['status'] ?? null);
        $this->assertFalse(filter_var($publishBox['publish_immediately'] ?? true, FILTER_VALIDATE_BOOL));
        $this->assertNotSame('2099', $publishBox['publish_year'] ?? null);
    }

    public function test_apply_publish_immediately_overrides_draft_status_in_returned_bundle(): void
    {
        $input = [
            'html' => '<p>x</p>',
            'publish_box' => [
                'publish_immediately' => true,
                'status' => 'draft',
                'publish_day' => '01',
                'publish_month' => '01',
                'publish_year' => '2099',
                'publish_hour' => '10',
                'publish_minute' => '00',
            ],
        ];

        $bundle = app(ArticleWpSyncQueueService::class)->applyPublishImmediatelyToBundle($input);
        $publishBox = is_array($bundle['publish_box'] ?? null) ? $bundle['publish_box'] : [];

        $this->assertSame('published', $publishBox['status'] ?? null);
        $this->assertTrue(filter_var($publishBox['publish_immediately'] ?? false, FILTER_VALIDATE_BOOL));
        $this->assertNotSame('2099', $publishBox['publish_year'] ?? null);
        // Input không bị mutate (PHP array copy) — caller phải gán lại.
        $this->assertSame('draft', $input['publish_box']['status'] ?? null);
    }
}
