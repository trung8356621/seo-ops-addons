<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Publishing\Services\ArticleScheduleReconcileService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ArticleScheduleReconcileServiceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_reconcile_skips_when_status_is_not_scheduled(): void
    {
        $this->requireSeoDatabaseConnection();

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Already published',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'type' => 'article',
        ]);

        $changed = app(ArticleScheduleReconcileService::class)->reconcileForEditor($article);

        $this->assertFalse($changed);
        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_reconcile_promotes_overdue_scheduled_article_without_wp_post(): void
    {
        $this->requireSeoDatabaseConnection();

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Overdue local schedule',
            'status' => 'scheduled',
            'published_at' => now()->subHour(),
            'wp_post_id' => 0,
            'type' => 'article',
        ]);

        $changed = app(ArticleScheduleReconcileService::class)->reconcileForEditor($article);

        $this->assertTrue($changed);
        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_reconcile_does_not_call_wordpress_for_overdue_with_wp_post(): void
    {
        $this->requireSeoDatabaseConnection();

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Overdue WP schedule',
            'status' => 'scheduled',
            'published_at' => now()->subMinutes(10),
            'wp_post_id' => 123,
            'type' => 'article',
        ]);

        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/publishing/src/Services/ArticleScheduleReconcileService.php',
        );
        $this->assertStringNotContainsString('WordPressArticleSyncService', $source);
        $this->assertStringNotContainsString('publishScheduledArticle', $source);

        $changed = app(ArticleScheduleReconcileService::class)->reconcileForEditor($article);

        $this->assertFalse($changed);
        $this->assertSame('scheduled', $article->fresh()->status);
        $this->assertSame(123, (int) $article->fresh()->wp_post_id);
    }

    public function test_schedule_label_visibility_helpers(): void
    {
        $service = app(ArticleScheduleReconcileService::class);

        $this->assertTrue($service->shouldShowScheduleLabel('scheduled'));
        $this->assertFalse($service->shouldShowScheduleLabel('published'));

        $this->assertTrue($service->shouldShowPublishedAtLabel('published', Carbon::now()->subDay()));
        $this->assertFalse($service->shouldShowPublishedAtLabel('scheduled', Carbon::now()->subDay()));
        $this->assertFalse($service->shouldShowPublishedAtLabel('published', null));
    }
}
