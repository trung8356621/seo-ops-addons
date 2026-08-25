<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Models\SeoArticleIndexCheck;
use Omnichannel\Addons\Seo\Models\SeoArticleIndexHealth;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthRecorder;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Tests\TestCase;

/**
 * Index Health persistence (requires SEO_TEST_USE_MYSQL=true + migrated tables).
 */
final class ArticleIndexHealthRecorderIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_article_index_checks')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_article_index_health')
        ) {
            $this->fail('Index Health tables missing — run local migration first (no SKIP).');
        }
    }

    public function test_first_check_indexed(): void
    {
        $article = $this->createPublishedArticle();
        $result = app(ArticleIndexHealthRecorder::class)->record(
            $article,
            ArticleIndexCheckStatus::Indexed,
            'manual',
            1,
            Carbon::parse('2026-08-01 10:00:00'),
        );

        self::assertSame('indexed', $result['effective_health']);
        $health = SeoArticleIndexHealth::query()->where('article_id', $article->id)->first();
        self::assertSame('indexed', $health?->current_status);
        self::assertNotNull($health?->last_checked_at);
    }

    public function test_first_not_indexed_is_not_dropped(): void
    {
        $article = $this->createPublishedArticle();
        $result = app(ArticleIndexHealthRecorder::class)->record(
            $article,
            ArticleIndexCheckStatus::NotIndexed,
            'manual',
            1,
        );

        self::assertSame('not_indexed', $result['effective_health']);
        self::assertFalse($result['transitioned_to_dropped']);
    }

    public function test_drop_and_recovery_preserves_history(): void
    {
        $article = $this->createPublishedArticle();
        $recorder = app(ArticleIndexHealthRecorder::class);

        $recorder->record($article, ArticleIndexCheckStatus::Indexed, 'manual', 1, Carbon::parse('2026-07-01'));
        $drop = $recorder->record($article, ArticleIndexCheckStatus::NotIndexed, 'manual', 1, Carbon::parse('2026-08-01'));
        self::assertSame('dropped', $drop['effective_health']);
        self::assertTrue($drop['transitioned_to_dropped']);

        $recover = $recorder->record($article, ArticleIndexCheckStatus::Indexed, 'manual', 1, Carbon::parse('2026-08-05'));
        self::assertSame('indexed', $recover['effective_health']);
        self::assertTrue($recover['recovered_from_dropped']);

        self::assertSame(3, SeoArticleIndexCheck::query()->where('article_id', $article->id)->count());
    }

    public function test_draft_observed_status_rejected(): void
    {
        $article = $this->createPublishedArticle('draft');
        $this->expectException(\RuntimeException::class);
        app(ArticleIndexHealthRecorder::class)->record($article, ArticleIndexCheckStatus::Indexed);
    }

    public function test_site_scope_isolation(): void
    {
        $a = $this->createPublishedArticle('publish', 9_401_001);
        $b = $this->createPublishedArticle('publish', 9_401_002);
        app(ArticleIndexHealthRecorder::class)->record($a, ArticleIndexCheckStatus::Indexed);

        self::assertSame(1, SeoArticleIndexHealth::query()->where('site_id', 9_401_001)->count());
        self::assertSame(0, SeoArticleIndexHealth::query()->where('site_id', 9_401_002)->where('article_id', $b->id)->count());
    }

    public function test_skip_seo_audit_still_eligible_to_record(): void
    {
        $article = $this->createPublishedArticle();
        if (Schema::connection('omi_seo_ai')->hasColumn('articles', 'skip_seo_audit')) {
            $article->forceFill(['skip_seo_audit' => true])->save();
        }

        $result = app(ArticleIndexHealthRecorder::class)->record($article, ArticleIndexCheckStatus::Indexed);
        self::assertSame('indexed', $result['effective_health']);
    }

    private function createPublishedArticle(string $observedStatus = 'publish', ?int $siteId = null): SeoArticle
    {
        $this->seq++;
        $siteId ??= 9_400_000 + ($this->seq % 1000);
        $token = 'ih-'.uniqid('', true);

        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'user_id' => 1,
            'title' => $token,
            'slug' => $token,
            'type' => 'article',
            'status' => 'publish',
            'language' => 'vi',
        ]);

        WordpressArticleLink::query()->create([
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            'wp_post_id' => 8_000_000 + $this->seq,
            'observed_post_status' => $observedStatus,
            'observed_permalink' => 'https://example-'.$siteId.'.test/'.$token.'/',
            'observed_at' => now(),
        ]);

        return $article->fresh(['wordpressLink']) ?? $article;
    }
}
