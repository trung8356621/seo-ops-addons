<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Feature;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\SearchFoundation\Http\Middleware\SetDynamicSeoDatabase;
use Omnichannel\Addons\Content\Enums\ArticleEditorSessionStatus;
use Omnichannel\Addons\WordPress\Jobs\ManualWordPressSyncJob;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleEditorSession;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\ArticleFeaturedImageProjection;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use Omnichannel\Addons\Media\Services\ArticleMediaLocalService;
use App\Models\Site;
use App\Models\SiteMeta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArticleEditorStandaloneFeaturedSyncWpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('seo_database_connections')) {
            DB::table('seo_database_connections')->update(['is_active' => false]);
        }
        Config::set('database.connections.mysql', []);
        Config::set('database.connections.omi_seo_ai', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('omi_seo_ai');
        DB::connection('omi_seo_ai')->getPdo();

        $this->createSeoTables();

        $this->app->bind(BusinessActionDispatcher::class, static fn (): BusinessActionDispatcher => new class implements BusinessActionDispatcher {
            public function dispatch(string $actionKey, array $input, ActionContext $context): ActionResult
            {
                if ($actionKey === 'article.content.update') {
                    $article = SeoArticle::query()->find((int) ($input['article_id'] ?? 0));
                    if ($article instanceof SeoArticle) {
                        $updates = [];
                        foreach (['content' => 'body', 'title' => 'title', 'slug' => 'slug', 'status' => 'status'] as $source => $target) {
                            if (array_key_exists($source, $input)) {
                                $updates[$target] = (string) $input[$source];
                            }
                        }
                        if ($updates !== []) {
                            $article->update($updates);
                        }
                    }
                }

                return ActionResult::success(['message' => 'saved']);
            }
        });
    }

    public function test_saved_standalone_featured_survives_sync_wp_and_sends_mapped_wp_attachment_id(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_OWNER,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);
        $site = Site::query()->create([
            'user_id' => $user->id,
            'domain' => 'wp-featured.test',
            'status' => 'active',
        ]);
        SiteMeta::query()->create(['site_id' => $site->id, 'meta_key' => 'seo_migration_token', 'meta_value' => 'write-token']);
        SiteMeta::query()->create(['site_id' => $site->id, 'meta_key' => 'wp_home_url', 'meta_value' => 'https://wp-featured.test']);

        $article = SeoArticle::query()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'title' => 'Standalone Article',
            'slug' => 'standalone-article',
            'body' => '<p>Saved body</p>',
            'status' => 'draft',
            'type' => 'article',
            'wp_post_id' => 456,
        ]);
        $media = SeoMedia::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'filename' => 'featured-x.jpg',
            'slug' => 'featured-x',
            'path' => '',
            'url' => 'https://wp-featured.test/wp-content/uploads/2026/08/featured-x.jpg',
            'source' => 'wordpress',
            'wp_attachment_id' => 987,
            'status' => 'trash',
        ]);

        app(ArticleMediaLocalService::class)->applyFeaturedLocal(
            $article,
            (int) $media->id,
            'https://wp-featured.test/wp-content/uploads/2026/08/featured-x.jpg',
        );
        app(ArticleFeaturedImageProjection::class)->rebuildAndPersist($article->fresh() ?? $article);

        $this->assertFeaturedState($article->fresh() ?? $article, (int) $media->id, 987, 'before sync controller');

        $mediaPayloads = [];
        Queue::fake();
        Http::fake(function ($request) use (&$mediaPayloads) {
            $url = (string) $request->url();
            if (str_contains($url, '/wp-json/omi-seo-ai/v1/posts/456/media')) {
                $mediaPayloads[] = $request->data();

                return Http::response([
                    'success' => true,
                    'message' => 'featured updated',
                    'featured_image_url' => 'https://wp-featured.test/wp-content/uploads/2026/08/featured-x.jpg',
                ]);
            }

            return Http::response(['success' => true, 'source_url' => 'https://wp-featured.test/wp-content/uploads/2026/08/featured-x.jpg']);
        });

        $response = $this
            ->withoutMiddleware(SetDynamicSeoDatabase::class)
            ->actingAs($user)
            ->postJson('/api/seo/articles/'.$article->id.'/sync-wp', [
            'html' => '<p>Saved body updated during sync</p>',
            'article_meta' => [
                'title' => 'Standalone Article',
                'slug' => 'standalone-article',
            ],
            'featured_image' => null,
            'product_album' => null,
            ], [
                'X-Site-ID' => (string) $site->id,
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        Queue::assertPushed(ManualWordPressSyncJob::class);
        $this->assertFeaturedState($article->fresh() ?? $article, (int) $media->id, 987, 'after sync response');

        app(ArticleMediaLocalService::class)->pushPendingMediaToWordPress($article->fresh() ?? $article);
        self::assertNotSame([], $mediaPayloads, 'WordPress media endpoint was not called.');
        self::assertSame(987, (int) ($mediaPayloads[0]['featured_attachment_id'] ?? 0));
        $this->assertFeaturedState($article->fresh() ?? $article, 987, 987, 'after media push');
        self::assertArrayNotHasKey('content_project_id', $response->json('data') ?? []);
    }

    public function test_unknown_local_featured_preserves_existing_wordpress_thumbnail(): void
    {
        [$user, $site, $article] = $this->makeStandaloneArticle();

        $mediaPayloads = [];
        Queue::fake();
        Http::fake(function ($request) use (&$mediaPayloads) {
            if (str_contains((string) $request->url(), '/wp-json/omi-seo-ai/v1/posts/456/media')) {
                $mediaPayloads[] = $request->data();

                return Http::response([
                    'success' => true,
                    'message' => 'no-op',
                    'featured_image_url' => 'https://wp-featured.test/wp-content/uploads/old.jpg',
                ]);
            }

            return Http::response(['success' => true]);
        });

        self::assertNull($article->articleMetas()->where('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)->value('meta_value'));

        $response = $this
            ->withoutMiddleware(SetDynamicSeoDatabase::class)
            ->actingAs($user)
            ->postJson('/api/seo/articles/'.$article->id.'/sync-wp', [
                'html' => '<p>Only text changed</p>',
                'article_meta' => ['title' => 'Standalone Article', 'slug' => 'standalone-article'],
                'featured_image' => null,
                'product_album' => null,
            ], ['X-Site-ID' => (string) $site->id]);

        $response->assertOk()->assertJsonPath('success', true);
        Queue::assertPushed(ManualWordPressSyncJob::class);

        $push = app(ArticleMediaLocalService::class)->pushPendingMediaToWordPress($article->fresh() ?? $article);
        self::assertFalse($push['attempted']);
        self::assertSame([], $mediaPayloads);
    }

    public function test_explicit_clear_featured_is_the_only_path_that_sends_clear_mutation(): void
    {
        [$user, , $article] = $this->makeStandaloneArticle();
        $media = $this->attachFeaturedMedia($article, 777);
        app(ArticleMediaLocalService::class)->clearFeaturedLocal($article->fresh() ?? $article);

        $mediaPayloads = [];
        Http::fake(function ($request) use (&$mediaPayloads) {
            if (str_contains((string) $request->url(), '/wp-json/omi-seo-ai/v1/posts/456/media')) {
                $mediaPayloads[] = $request->data();

                return Http::response([
                    'success' => true,
                    'message' => 'featured cleared',
                    'featured_image_url' => '',
                ]);
            }

            return Http::response(['success' => true]);
        });

        $this->actingAs($user);
        $push = app(ArticleMediaLocalService::class)->pushPendingMediaToWordPress($article->fresh() ?? $article);

        self::assertTrue($push['attempted']);
        self::assertTrue($push['success']);
        self::assertSame(0, (int) ($mediaPayloads[0]['featured_attachment_id'] ?? -1));
        self::assertNull($article->fresh()?->articleMetas()->where('meta_key', ArticleMediaLocalService::META_FEATURED_CLEAR_PENDING)->value('meta_value'));
        self::assertSame(777, (int) $media->wp_attachment_id);
    }

    public function test_generic_null_or_pending_without_clear_does_not_emit_featured_clear(): void
    {
        [$user, , $article] = $this->makeStandaloneArticle();
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => ArticleMediaLocalService::META_MEDIA_PENDING_SYNC],
            ['meta_value' => '1'],
        );

        $mediaPayloads = [];
        Http::fake(function ($request) use (&$mediaPayloads) {
            if (str_contains((string) $request->url(), '/wp-json/omi-seo-ai/v1/posts/456/media')) {
                $mediaPayloads[] = $request->data();

                return Http::response(['success' => true, 'message' => 'unexpected']);
            }

            return Http::response(['success' => true]);
        });

        $this->actingAs($user);
        $push = app(ArticleMediaLocalService::class)->pushPendingMediaToWordPress($article->fresh() ?? $article);

        self::assertTrue($push['attempted']);
        self::assertTrue($push['success']);
        self::assertSame([], $mediaPayloads);
    }

    public function test_same_user_tabs_keep_independent_active_leases(): void
    {
        [$user, , $article] = $this->makeStandaloneArticle();
        $service = app(ArticleEditorSessionService::class);
        $clientA = (string) Str::uuid();
        $clientB = (string) Str::uuid();

        $first = $service->acquire($article, $user, $clientA);
        $sessionA = (string) $first['session']['id'];
        $second = $service->acquire($article->fresh() ?? $article, $user, $clientB);
        $sessionB = (string) $second['session']['id'];

        self::assertNotSame($sessionA, $sessionB);
        self::assertSame(ArticleEditorSessionStatus::Active, SeoArticleEditorSession::query()->find($sessionA)?->status);
        self::assertSame(ArticleEditorSessionStatus::Active, SeoArticleEditorSession::query()->find($sessionB)?->status);

        $service->heartbeat($article->fresh() ?? $article, $sessionA, $user);
        $service->heartbeat($article->fresh() ?? $article, $sessionB, $user);
        self::assertSame(ArticleEditorSessionStatus::Active, SeoArticleEditorSession::query()->find($sessionA)?->status);
        self::assertSame(ArticleEditorSessionStatus::Active, SeoArticleEditorSession::query()->find($sessionB)?->status);
    }

    public function test_same_user_lock_allows_sync_guards_but_different_user_still_blocks(): void
    {
        [$user, , $article] = $this->makeStandaloneArticle();
        $other = User::factory()->create([
            'role' => User::ROLE_OWNER,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);
        $service = app(ArticleEditorSessionService::class);
        $active = $service->acquire($article, $user, (string) Str::uuid());

        $this->actingAs($user);
        $service->assertNoActiveEditorSession($article->fresh() ?? $article, 'sync_from_wordpress');
        $service->assertBodyRewriteAllowed($article->fresh() ?? $article, 'sync_from_wordpress', (string) Str::uuid(), $user);
        self::assertNull($service->userFacingSaveBlockedByForeignSession($article->fresh() ?? $article, $user));

        $this->actingAs($other);
        $this->expectException(ArticleEditorSessionException::class);
        $service->assertNoActiveEditorSession($article->fresh() ?? $article, 'sync_from_wordpress');

        self::assertNotEmpty($active['session']['id']);
    }

    public function test_article_write_lock_for_one_article_does_not_block_another(): void
    {
        $articleALock = Cache::lock(ActionSupport::articleWriteLockKey(2754), 30);
        self::assertTrue($articleALock->get());

        try {
            $result = ActionSupport::withArticleLock(2383, static fn (): string => 'article-b-saved');
            self::assertSame('article-b-saved', $result);
        } finally {
            $articleALock->release();
        }
    }

    public function test_same_article_write_lock_fails_fast(): void
    {
        $heldLock = Cache::lock(ActionSupport::articleWriteLockKey(2754), 30);
        self::assertTrue($heldLock->get());
        $startedAt = hrtime(true);

        try {
            ActionSupport::withArticleLock(2754, static fn (): bool => true);
            self::fail('Concurrent write should fail fast.');
        } catch (\RuntimeException $exception) {
            self::assertSame('article_write_busy', $exception->getMessage());
            self::assertLessThan(250, (hrtime(true) - $startedAt) / 1_000_000);
        } finally {
            $heldLock->release();
        }
    }

    private function makeStandaloneArticle(): array
    {
        $user = User::factory()->create([
            'role' => User::ROLE_OWNER,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);
        $site = Site::query()->create([
            'user_id' => $user->id,
            'domain' => 'wp-featured.test',
            'status' => 'active',
        ]);
        SiteMeta::query()->create(['site_id' => $site->id, 'meta_key' => 'seo_migration_token', 'meta_value' => 'write-token']);
        SiteMeta::query()->create(['site_id' => $site->id, 'meta_key' => 'seo_read_token', 'meta_value' => 'read-token']);
        SiteMeta::query()->create(['site_id' => $site->id, 'meta_key' => 'wp_home_url', 'meta_value' => 'https://wp-featured.test']);
        SiteMeta::query()->create(['site_id' => $site->id, 'meta_key' => 'seo_platform', 'meta_value' => 'wordpress']);

        $article = SeoArticle::query()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'title' => 'Standalone Article',
            'slug' => 'standalone-article',
            'body' => '<p>Saved body</p>',
            'status' => 'draft',
            'type' => 'article',
            'wp_post_id' => 456,
        ]);

        return [$user, $site, $article];
    }

    private function attachFeaturedMedia(SeoArticle $article, int $wpAttachmentId): SeoMedia
    {
        $media = SeoMedia::query()->create([
            'site_id' => $article->site_id,
            'article_id' => $article->id,
            'filename' => 'featured-x.jpg',
            'slug' => 'featured-x',
            'path' => '',
            'url' => 'https://wp-featured.test/wp-content/uploads/2026/08/featured-x.jpg',
            'source' => 'wordpress',
            'wp_attachment_id' => $wpAttachmentId,
            'status' => 'trash',
        ]);

        app(ArticleMediaLocalService::class)->applyFeaturedLocal(
            $article,
            (int) $media->id,
            'https://wp-featured.test/wp-content/uploads/2026/08/featured-x.jpg',
        );

        return $media;
    }

    private function assertFeaturedState(SeoArticle $article, int $expectedStoredRefId, int $expectedWpAttachmentId, string $checkpoint): void
    {
        $article->unsetRelation('articleMetas');
        $article->load('articleMetas');
        $url = (string) $article->articleMetas->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_URL)?->meta_value;
        $refId = (int) $article->articleMetas->firstWhere('meta_key', ArticleMediaLocalService::META_FEATURED_ATTACHMENT_ID)?->meta_value;

        self::assertSame('https://wp-featured.test/wp-content/uploads/2026/08/featured-x.jpg', $url, $checkpoint.' url');
        self::assertSame($expectedStoredRefId, $refId, $checkpoint.' stored ref');
        self::assertSame('https://wp-featured.test/wp-content/uploads/2026/08/featured-x.jpg', (string) $article->featured_thumb_url, $checkpoint.' projection url');

        $media = SeoMedia::query()->where('wp_attachment_id', $expectedWpAttachmentId)->first();
        self::assertInstanceOf(SeoMedia::class, $media, $checkpoint.' mapped SeoMedia');
    }

    private function createSeoTables(): void
    {
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->longText('body')->nullable();
            $table->string('status')->default('draft');
            $table->string('type')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('wp_sync_status')->default('idle');
            $table->unsignedBigInteger('wp_sync_job_id')->nullable();
            $table->string('featured_thumb_url')->nullable();
            $table->unsignedBigInteger('featured_media_id')->nullable();
            $table->string('featured_image_status')->nullable();
            $table->string('featured_image_source')->nullable();
            $table->integer('document_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('omi_seo_ai')->create('article_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->string('meta_key')->index();
            $table->longText('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->string('filename');
            $table->string('slug');
            $table->string('path');
            $table->string('url');
            $table->string('source')->default('wordpress');
            $table->unsignedBigInteger('wp_attachment_id')->nullable()->index();
            $table->timestamp('wp_synced_at')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_media_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_id')->index();
            $table->string('meta_key')->index();
            $table->longText('meta_value')->nullable();
            $table->timestamps();
            $table->unique(['media_id', 'meta_key']);
        });

        Schema::connection('omi_seo_ai')->create('seo_image_optimization_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->boolean('auto_convert_webp')->default(false);
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_project_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->timestamp('archived_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_article_wp_sync_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('status', 32)->index();
            $table->string('idempotency_key')->index();
            $table->string('mode')->default('sync');
            $table->string('source')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->string('request_id')->nullable();
            $table->string('correlation_id')->nullable();
            $table->string('worker_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('wordpress_permalink')->nullable();
            $table->string('stage')->nullable();
            $table->json('settings')->nullable();
            $table->json('audit_meta')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('article_editor_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('article_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('status', 32)->index();
            $table->uuid('client_instance_id')->nullable()->index();
            $table->timestamp('acquired_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('takeover_by_user_id')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('meta_key')->index();
            $table->longText('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_faqs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->text('question');
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }
}
