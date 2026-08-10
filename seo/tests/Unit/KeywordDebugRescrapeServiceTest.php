<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Services\ArticleKeywordLinkReconcileService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordDebugRescrapeService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class KeywordDebugRescrapeServiceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_delete_and_rescrape_rescans_linked_articles_via_context_map(): void
    {
        $this->requireSeoDatabaseConnection();
        $this->mockSeoLinkMapDependencies();

        $suffix = uniqid('kw_debug_linked_', true);
        $phrase = 'debug linked '.$suffix;

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Debug linked',
            'body' => '<p>Text <a href="/page">'.$phrase.'</a> end.</p>',
            'status' => 'publish',
            'type' => 'article',
        ]);

        app(ArticleKeywordLinkReconcileService::class)->reconcileForArticle($article);

        $keyword = Keyword::query()->where('phrase', $phrase)->first();
        $this->assertInstanceOf(Keyword::class, $keyword);

        $summary = app(KeywordDebugRescrapeService::class)->deleteAndRescrapeLinkedArticles($keyword);

        $this->assertSame($phrase, $summary['phrase']);
        $this->assertContains($article->id, $summary['linked_article_ids']);
        $this->assertTrue($summary['deleted']);
        $this->assertSame(1, $summary['rescanned']);
        $this->assertFalse($summary['recreated']);
        $this->assertFalse(Keyword::query()->where('phrase', $phrase)->exists());
    }

    public function test_delete_and_rescrape_removes_keyword_without_linked_articles(): void
    {
        $this->requireSeoDatabaseConnection();
        $suffix = uniqid('kw_debug_', true);
        $phrase = 'debug keyword '.$suffix;
        $keyword = app(KeywordPersistenceService::class)->upsert($phrase, Keyword::TYPE_NORMAL, 2, '/debug');

        $this->assertNotNull($keyword);

        $summary = app(KeywordDebugRescrapeService::class)->deleteAndRescrapeLinkedArticles($keyword);

        $this->assertSame($phrase, $summary['phrase']);
        $this->assertSame([], $summary['linked_article_ids']);
        $this->assertTrue($summary['deleted']);
        $this->assertSame(0, $summary['rescanned']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertFalse(Keyword::query()->where('phrase', $phrase)->exists());
    }

    private function mockSeoLinkMapDependencies(): void
    {
        Queue::fake();
        $this->instance(SeoKeywordSettingsService::class, SeoKeywordSettingsService::withDefaults());
    }
}
