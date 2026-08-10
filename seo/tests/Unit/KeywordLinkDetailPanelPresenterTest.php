<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class KeywordLinkDetailPanelPresenterTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_site_links_count_excludes_ignored_maps(): void
    {
        $this->requireSeoDatabaseConnection();

        $keyword = Keyword::query()->create([
            'phrase' => 'ignored map count test',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Single article',
            'body' => '<p>Text</p>',
            'status' => 'publish',
            'type' => 'article',
        ]);

        SeoLinkMap::query()->create([
            'keyword_id' => $keyword->id,
            'source_article_id' => $article->id,
            'anchor_text' => 'ignored map count test active',
            'link_type' => SeoLinkMapType::External,
            'status' => SeoLinkMapStatus::Active,
            'target_external_url' => 'https://example.test/target-active',
        ]);

        SeoLinkMap::query()->create([
            'keyword_id' => $keyword->id,
            'source_article_id' => $article->id,
            'anchor_text' => 'ignored map count test ignored',
            'link_type' => SeoLinkMapType::External,
            'status' => SeoLinkMapStatus::Ignored,
            'target_external_url' => 'https://example.test/target-ignored',
        ]);

        $keyword->loadCount(Keyword::linkMapCountRelations());

        $this->assertSame(2, (int) $keyword->linked_articles_count);
        $this->assertSame(1, (int) $keyword->site_links_count);
    }
}
