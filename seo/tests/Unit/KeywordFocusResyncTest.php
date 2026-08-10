<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordDomainResyncService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class KeywordFocusResyncTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_domain_resync_keeps_focus_keyword_without_link_maps(): void
    {
        $this->requireSeoDatabaseConnection();
        Queue::fake();
        $this->instance(SeoKeywordSettingsService::class, SeoKeywordSettingsService::withDefaults());

        $suffix = uniqid('kw_focus_', true);
        $phrase = 'focus keyword '.$suffix;

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Focus article',
            'body' => '<p>Content</p>',
            'status' => 'publish',
            'type' => 'article',
        ]);

        $article->articleMetas()->create([
            'meta_key' => 'seo_focus_keyword',
            'meta_value' => $phrase,
        ]);

        KeywordFocusAttach::syncMainKeyword($article, 2, 0, $phrase);

        $keyword = Keyword::query()->where('phrase', $phrase)->first();
        $this->assertInstanceOf(Keyword::class, $keyword);

        app(KeywordDomainResyncService::class)->resetAndResync(2);

        $this->assertTrue(Keyword::query()->whereKey($keyword->id)->exists());
        $this->assertSame(
            (int) $article->id,
            app(KeywordMetaRepository::class)->getMainArticleId((int) $keyword->id),
        );
    }

    public function test_orphan_focus_keyword_is_restored_from_article_meta(): void
    {
        $this->requireSeoDatabaseConnection();
        Queue::fake();
        $this->instance(SeoKeywordSettingsService::class, SeoKeywordSettingsService::withDefaults());

        $suffix = uniqid('kw_restore_', true);
        $phrase = 'restore focus '.$suffix;

        $article = SeoArticle::query()->create([
            'site_id' => 2,
            'title' => 'Restore focus',
            'body' => '<p>Content</p>',
            'status' => 'publish',
            'type' => 'article',
        ]);

        $article->articleMetas()->create([
            'meta_key' => 'seo_focus_keyword',
            'meta_value' => $phrase,
        ]);

        $keyword = app(KeywordPersistenceService::class)->upsert($phrase, Keyword::TYPE_NORMAL, 2);
        $this->assertNotNull($keyword);

        app(KeywordMetaRepository::class)->setMainArticleId((int) $keyword->id, (int) $article->id);

        app(KeywordDomainResyncService::class)->resetAndResync(2);

        $this->assertSame(
            (int) $article->id,
            app(KeywordMetaRepository::class)->getMainArticleId((int) $keyword->id),
        );

        $this->assertDatabaseHas('keyword_meta', [
            'keyword_id' => $keyword->id,
            'meta_key' => KeywordMetaKey::MainArticleId->value,
            'meta_value' => (string) $article->id,
        ], 'omi_seo_ai');
    }
}
