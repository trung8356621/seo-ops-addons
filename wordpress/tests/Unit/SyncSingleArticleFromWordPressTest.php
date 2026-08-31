<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use App\Models\Site;
use App\Models\SiteMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SyncSingleArticleFromWordPressTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['mysql', 'omi_seo_ai'];

    public function test_fails_before_write_when_wordpress_returns_no_items(): void
    {
        $this->requireSeoDatabaseConnection();
        Queue::fake();

        [$site, $article] = $this->createLinkedArticleWithLocalBody();

        Http::fake([
            'https://example-sync.test/wp-json/omi-seo-ai/v1/sync/items' => Http::response([
                'success' => true,
                'items' => [],
            ], 200),
        ]);

        $result = app(SyncDomainContentService::class)->syncSingleArticleFromWordPress($article);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Không lấy được bài từ WordPress', (string) $result['message']);

        $article->refresh();
        $this->assertSame('<p>Local body must survive</p>', (string) $article->body);
        $this->assertSame('Local title', (string) $article->title);
        $this->assertNull(
            $article->articleMetas()->where('meta_key', SyncDomainContentService::META_PULL_SYNC_AUDIT)->first(),
        );

        Http::assertSentCount(1);
        unset($site);
    }

    public function test_force_overwrite_replaces_local_body_and_clears_conflict_flags(): void
    {
        $this->requireSeoDatabaseConnection();
        Queue::fake();

        [$site, $article] = $this->createLinkedArticleWithLocalBody();

        $flags = app(ArticleWordPressSyncFlagService::class);
        $flags->markLocalEditPending($article);
        $flags->markDataOutOfSync($article);

        Http::fake([
            'https://example-sync.test/wp-json/omi-seo-ai/v1/sync/items' => Http::response([
                'success' => true,
                'items' => [[
                    'wp_id' => (int) $article->wp_post_id,
                    'type' => 'article',
                    'wp_post_type' => 'post',
                    'wp_entity' => 'post',
                    'title' => 'WordPress Title',
                    'slug' => 'wordpress-title',
                    'permalink' => 'https://example-sync.test/wordpress-title/',
                    // Raw thiếu img → inject từ post_images; không dùng scoring.body (tránh entity encode).
                    'post_content' => '<h2>Section A</h2><p>Fresh from WordPress</p><h2>Section B</h2><p>More text</p>',
                    'scoring' => [
                        'body' => '<p class="font-claude-response-body">V&#x1EA3;i rendered — must not win</p>'
                            .'<img src="https://example-sync.test/rendered.jpg" />',
                        'slug' => 'wordpress-title',
                        'seo_title' => 'WP SEO Title',
                        'meta_description' => 'WP meta',
                        'focus_keyword' => 'wp keyword',
                    ],
                    'category_ids' => [3, 7],
                    'faqs' => [],
                    'featured_image_url' => 'https://example-sync.test/featured.jpg',
                    'post_images' => [[
                        'wp_attachment_id' => 55,
                        'src' => 'https://example-sync.test/a.jpg',
                        'wp_url' => 'https://example-sync.test/a.jpg',
                        'alt' => 'Alt A',
                        'caption' => 'Cap A',
                    ]],
                    'status' => 'publish',
                    'post_date' => '2026-01-01 10:00:00',
                    'post_modified' => '2026-06-01 12:00:00',
                    'published_at' => '2026-01-01T10:00:00+00:00',
                    'seo' => [
                        'seo_title' => 'WP SEO Title',
                        'meta_description' => 'WP meta',
                        'focus_keyword' => 'wp keyword',
                    ],
                ]],
            ], 200),
        ]);

        $result = app(SyncDomainContentService::class)->syncSingleArticleFromWordPress($article->fresh());

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame(1, (int) ($result['inline_images'] ?? 0));
        $this->assertSame([3, 7], $result['category_ids'] ?? []);

        $article->refresh();
        $this->assertNull($article->body);
        $this->assertSame('WordPress Title', (string) $article->title);
        $this->assertFalse($flags->hasLocalEditPending($article));
        $this->assertFalse($flags->hasDataOutOfSync($article));

        $this->assertNull(
            $article->articleMetas()->where('meta_key', 'wp_post_content')->value('meta_value'),
        );
        $this->assertNull($article->body);

        $auditRaw = $article->articleMetas()
            ->where('meta_key', SyncDomainContentService::META_PULL_SYNC_AUDIT)
            ->value('meta_value');
        $this->assertNotEmpty($auditRaw);
        $audit = json_decode((string) $auditRaw, true);
        $this->assertIsArray($audit);
        $this->assertSame((int) $article->id, (int) ($audit['article_id'] ?? 0));
        $this->assertSame((int) $article->wp_post_id, (int) ($audit['wp_post_id'] ?? 0));
        $this->assertSame(1, (int) ($audit['inline_images'] ?? 0));
        $this->assertNotEmpty($audit['previous_content_checksum'] ?? null);
        $this->assertNotEmpty($audit['new_content_checksum'] ?? null);

        unset($site);
    }

    /**
     * @return array{0: Site, 1: SeoArticle}
     */
    private function createLinkedArticleWithLocalBody(): array
    {
        $site = Site::query()->create([
            'name' => 'Sync single test',
            'domain' => 'example-sync.test',
            'user_id' => 1,
            'status' => 1,
        ]);

        SiteMeta::query()->create([
            'site_id' => $site->id,
            'meta_key' => 'seo_platform',
            'meta_value' => 'wordpress',
        ]);
        SiteMeta::query()->create([
            'site_id' => $site->id,
            'meta_key' => 'seo_read_token',
            'meta_value' => 'test-read-token',
        ]);

        $article = SeoArticle::query()->create([
            'site_id' => $site->id,
            'wp_post_id' => 99001,
            'title' => 'Local title',
            'body' => '<p>Local body must survive</p>',
            'status' => 'draft',
            'type' => 'article',
        ]);

        $article->articleMetas()->create([
            'meta_key' => 'wp_entity',
            'meta_value' => 'post',
        ]);

        return [$site->fresh(['metas']), $article->fresh(['articleMetas', 'site.metas'])];
    }
}
