<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;
use Tests\Support\LegacyAddonPath;
use Tests\TestCase;

final class KeywordWorkspaceTotalBadgeTest extends TestCase
{
    private const SITE_A = 2;

    private const SITE_B = 99;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('omi_seo_ai');
        $this->ensureTables();
    }

    public function test_dictionary_and_focus_overlap_counts_once_in_total(): void
    {
        $articleId = $this->createArticle(self::SITE_A, 'vi', 'Overlap article');

        // Dictionary-only (linked inventory, no focus).
        $this->createInventoryKeyword('alpha phrase one', $articleId, withFocus: false);
        $this->createInventoryKeyword('bravo phrase two', $articleId, withFocus: false);

        // Overlap: same keyword row is Dictionary inventory + Focus.
        $this->createInventoryKeyword('charlie focus phrase', $articleId, withFocus: true);

        $variants = ['vi'];
        $total = app(KeywordUiInventoryQuery::class)->count(self::SITE_A, $variants);
        $dictionary = $total;
        $focus = (int) app(KeywordDictionaryQuery::class)
            ->filtered(self::SITE_A, $variants, ['focus' => true])
            ->count();

        self::assertSame(3, $total);
        self::assertSame(3, $dictionary);
        self::assertSame(1, $focus);
        self::assertNotSame($dictionary + $focus, $total);
    }

    public function test_total_is_distinct_inventory_not_dictionary_plus_focus_sum(): void
    {
        $articleId = $this->createArticle(self::SITE_A, 'vi', 'Inventory article');

        // 10 inventory rows; 4 also Focus (overlap = 4). Blind sum would be 14.
        for ($i = 1; $i <= 10; $i++) {
            $this->createInventoryKeyword(
                sprintf('inventory phrase %02d', $i),
                $articleId,
                withFocus: $i <= 4,
            );
        }

        $variants = ['vi'];
        $total = app(KeywordUiInventoryQuery::class)->count(self::SITE_A, $variants);
        $dictionary = $total;
        $focus = (int) app(KeywordDictionaryQuery::class)
            ->filtered(self::SITE_A, $variants, ['focus' => true])
            ->count();

        self::assertSame(10, $total);
        self::assertSame(10, $dictionary);
        self::assertSame(4, $focus);
        self::assertSame(14, $dictionary + $focus);
        self::assertNotSame($dictionary + $focus, $total);
    }

    public function test_total_is_site_isolated(): void
    {
        $articleA = $this->createArticle(self::SITE_A, 'vi', 'Site A article');
        $articleB = $this->createArticle(self::SITE_B, 'vi', 'Site B article');

        $this->createInventoryKeyword('site a phrase one', $articleA, withFocus: false);
        $this->createInventoryKeyword('site a phrase two', $articleA, withFocus: true);
        $this->createInventoryKeyword('site b phrase only', $articleB, withFocus: true);

        $variants = ['vi'];
        $totalA = app(KeywordUiInventoryQuery::class)->count(self::SITE_A, $variants);
        $totalB = app(KeywordUiInventoryQuery::class)->count(self::SITE_B, $variants);

        self::assertSame(2, $totalA);
        self::assertSame(1, $totalB);
    }

    public function test_total_respects_language_filter_like_module_nav(): void
    {
        $viArticle = $this->createArticle(self::SITE_A, 'vi', 'VI article');
        $enArticle = $this->createArticle(self::SITE_A, 'en', 'EN article');

        $this->createInventoryKeyword('tieng viet phrase', $viArticle, withFocus: false);
        $this->createInventoryKeyword('another viet phrase', $viArticle, withFocus: true);
        $this->createInventoryKeyword('english only phrase', $enArticle, withFocus: false);

        $inventory = app(KeywordUiInventoryQuery::class);

        self::assertSame(2, $inventory->count(self::SITE_A, ['vi']));
        self::assertSame(1, $inventory->count(self::SITE_A, ['en']));
        self::assertSame(3, $inventory->count(self::SITE_A, null));
    }

    public function test_header_total_badge_shared_across_keyword_tabs(): void
    {
        $navTrait = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/HasKeywordWorkspaceNavigation.php');
        $navBlade = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-workspace-nav.blade.php',
        ));

        self::assertStringContainsString('getKeywordWorkspaceTotalKeywords', $navTrait);
        self::assertStringContainsString('KeywordUiInventoryQuery', $navTrait);
        self::assertStringContainsString("'total' => \$total", $navTrait);
        self::assertStringContainsString('getKeywordWorkspaceTotalKeywords', $navBlade);
        self::assertStringContainsString('keyword-module-header__total-badge', $navBlade);
        self::assertStringContainsString('module_total_tooltip', $navBlade);

        $pages = [
            'list-keywords.blade.php',
            'topic-cluster-index.blade.php',
            'keyword-cannibalization-workspace.blade.php',
            'anchor-text-audit-workspace.blade.php',
        ];

        foreach ($pages as $page) {
            $src = (string) file_get_contents(LegacyAddonPath::resolve(
                'resources/views/filament/resources/keywords/pages/'.$page,
            ));
            self::assertStringContainsString(
                'keyword-workspace-nav',
                $src,
                "Expected shared nav (with Total badge) on {$page}",
            );
        }
    }

    public function test_navigation_cache_keys_by_language_and_exposes_total(): void
    {
        $nav = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/HasKeywordWorkspaceNavigation.php');

        self::assertStringContainsString('keywordWorkspaceTabCountsCacheKey', $nav);
        self::assertStringContainsString('getKeywordWorkspaceTotalKeywords', $nav);
        self::assertStringContainsString("'total' => \$total", $nav);
        self::assertStringContainsString('$dictionary = $total', $nav);
    }

    private function createInventoryKeyword(string $phrase, int $sourceArticleId, bool $withFocus): int
    {
        $keyword = Keyword::query()->create([
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => 'active',
        ]);

        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => (int) $keyword->id,
            'source_article_id' => $sourceArticleId,
            'target_article_id' => $sourceArticleId,
            'anchor_text' => $phrase,
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withFocus) {
            DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
                'keyword_id' => (int) $keyword->id,
                'meta_key' => KeywordMetaKey::MainArticleId->value,
                'meta_value' => (string) $sourceArticleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) $keyword->id;
    }

    private function createArticle(int $siteId, string $language, string $title): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => $siteId,
            'title' => $title,
            'language' => $language,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureTables(): void
    {
        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->string('review_status')->default('active');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('title')->nullable();
            $table->string('language')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->unsignedBigInteger('source_article_id')->index();
            $table->unsignedBigInteger('target_article_id')->nullable()->index();
            $table->text('anchor_text');
            $table->string('link_type')->default('internal');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
    }
}
