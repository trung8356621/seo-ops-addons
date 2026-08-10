<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\ArticleKeywordLinkReconcileService;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ArticleKeywordLinkReconcileServiceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_reconcile_for_article_creates_seo_link_maps_from_content(): void
    {
        $this->requireSeoDatabaseConnection();
        $this->mockSeoLinkMapDependencies();

        $suffix = uniqid('kw_reconcile_', true);
        $phrase = 'anchor reconcile '.$suffix;

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Reconcile test',
            'body' => '<p>Before <a href="/target-page">'.$phrase.'</a> after.</p>',
            'status' => 'publish',
            'type' => 'article',
        ]);

        app(ArticleKeywordLinkReconcileService::class)->reconcileForArticle($article);

        $this->assertTrue(
            SeoLinkMap::query()
                ->where('source_article_id', $article->id)
                ->whereHas('keyword', static fn ($query) => $query->where('phrase', $phrase))
                ->exists(),
        );
    }

    private function mockSeoLinkMapDependencies(): void
    {
        Queue::fake();
        $this->instance(SeoKeywordSettingsService::class, SeoKeywordSettingsService::withDefaults());
    }
}
