<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class KeywordPersistenceServiceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_upsert_creates_global_keyword_and_site_meta(): void
    {
        $this->requireSeoDatabaseConnection();
        $suffix = uniqid('kw_persist_', true);
        $phrase = 'balo quảng cáo '.$suffix;
        $service = app(KeywordPersistenceService::class);

        $first = $service->upsert($phrase, Keyword::TYPE_NORMAL, 2, '/a');
        $second = $service->upsert($phrase, Keyword::TYPE_NORMAL, 3, '/b');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Keyword::query()->where('phrase', $phrase)->count());
        $this->assertSame('/a', $first->fresh()?->targetUrlForSite(2));
        $this->assertSame('/b', $second->fresh()?->targetUrlForSite(3));

        $this->assertDatabaseHas('keyword_meta', [
            'keyword_id' => $first->id,
            'meta_key' => KeywordMetaKey::siteTargetUrl(2),
            'meta_value' => '/a',
        ], 'omi_seo_ai');

        $this->assertDatabaseHas('keyword_meta', [
            'keyword_id' => $second->id,
            'meta_key' => KeywordMetaKey::siteTargetUrl(3),
            'meta_value' => '/b',
        ], 'omi_seo_ai');
    }

    public function test_free_keyword_upsert_sets_rescrape_keep_in_site_meta(): void
    {
        $this->requireSeoDatabaseConnection();
        $suffix = uniqid('kw_free_', true);
        $phrase = 'free keyword '.$suffix;
        $service = app(KeywordPersistenceService::class);

        $keyword = $service->upsert($phrase, Keyword::TYPE_FREE, 2);
        $this->assertNotNull($keyword);

        $this->assertDatabaseHas('keyword_meta', [
            'keyword_id' => $keyword->id,
            'meta_key' => KeywordMetaKey::siteRescrapeKeep(2),
            'meta_value' => '1',
        ], 'omi_seo_ai');

        $this->assertTrue($keyword->fresh()?->keepOnRescrapeForSite(2));
    }

    public function test_upsert_returns_null_for_empty_phrase(): void
    {
        $this->requireSeoDatabaseConnection();
        $service = app(KeywordPersistenceService::class);

        $this->assertNull($service->upsert('   ', Keyword::TYPE_NORMAL, 2));
        $this->assertNull($service->upsert('&nbsp;', Keyword::TYPE_NORMAL, 2));
    }

    public function test_upsert_uses_primary_focus_phrase_when_rank_math_lists_multiple(): void
    {
        $this->requireSeoDatabaseConnection();
        $suffix = uniqid('kw_multi_', true);
        $raw = 'balo quảng cáo '.$suffix.', balo phụ '.$suffix;
        $service = app(KeywordPersistenceService::class);

        $keyword = $service->upsert($raw, Keyword::TYPE_NORMAL, 2, '/a');

        $this->assertNotNull($keyword);
        $this->assertSame('balo quảng cáo '.$suffix, (string) $keyword->phrase);
    }
}
