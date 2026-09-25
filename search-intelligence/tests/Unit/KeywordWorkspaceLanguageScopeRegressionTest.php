<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordTopicAssignmentStats;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordUiInventoryQuery;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordWorkspaceLanguageScope;
use Tests\TestCase;

/**
 * Multilingual Keywords workspace must scope inventory, Topic cards, counts, and AI Audit
 * by the existing selector (`keywordLanguageFilter` → languageVariants) — not site_id only.
 */
final class KeywordWorkspaceLanguageScopeRegressionTest extends TestCase
{
    private const SITE_A = 11;

    private const SITE_NORMAL = 22;

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
        $this->seedMultilingualLandscape();
    }

    public function test_vi_landscape_excludes_en_keywords_topics_and_counts(): void
    {
        $variants = ['vi'];
        $inventory = app(KeywordUiInventoryQuery::class);
        $stats = app(KeywordTopicAssignmentStats::class)->forSite(self::SITE_A, $variants);
        $summary = app(TopicListQuery::class)->summary(self::SITE_A, $variants);
        $rows = app(TopicListQuery::class)->paginate(self::SITE_A, ['per_page' => 50], $variants)->items();

        self::assertSame(40, $inventory->count(self::SITE_A, $variants));
        self::assertSame(10, (int) app(KeywordDictionaryQuery::class)
            ->filtered(self::SITE_A, $variants, ['focus' => true])
            ->count());
        self::assertSame(2, $stats['assigned']);
        self::assertSame(38, $stats['unassigned']);
        self::assertSame(40, $stats['assigned'] + $stats['unassigned']);
        self::assertSame(2, $stats['topic_count']);
        self::assertSame(2, $summary['topic_count']);
        self::assertSame(40, $summary['inventory_total']);
        self::assertSame(2, $summary['assigned']);
        self::assertSame(38, $summary['unassigned']);

        self::assertCount(2, $rows);
        self::assertSame(['Mixed Topic', 'VI Bags'], collect($rows)->pluck('name')->sort()->values()->all());
        self::assertSame([1, 1], collect($rows)->pluck('keyword_count')->sort()->values()->all());
    }

    public function test_en_landscape_excludes_vi_keywords_topics_and_counts(): void
    {
        $variants = ['en'];
        $inventory = app(KeywordUiInventoryQuery::class);
        $stats = app(KeywordTopicAssignmentStats::class)->forSite(self::SITE_A, $variants);
        $summary = app(TopicListQuery::class)->summary(self::SITE_A, $variants);
        $rows = app(TopicListQuery::class)->paginate(self::SITE_A, ['per_page' => 50], $variants)->items();

        self::assertSame(60, $inventory->count(self::SITE_A, $variants));
        self::assertSame(15, (int) app(KeywordDictionaryQuery::class)
            ->filtered(self::SITE_A, $variants, ['focus' => true])
            ->count());
        self::assertSame(2, $stats['assigned']);
        self::assertSame(58, $stats['unassigned']);
        self::assertSame(60, $stats['assigned'] + $stats['unassigned']);
        self::assertSame(2, $stats['topic_count']);
        self::assertSame(2, $summary['topic_count']);
        self::assertSame(60, $summary['inventory_total']);

        self::assertCount(2, $rows);
        self::assertSame(['EN Laptops', 'Mixed Topic'], collect($rows)->pluck('name')->sort()->values()->all());
        self::assertSame([1, 1], collect($rows)->pluck('keyword_count')->sort()->values()->all());
    }

    public function test_switching_vi_to_en_changes_dataset_and_aggregates(): void
    {
        $vi = app(TopicListQuery::class)->summary(self::SITE_A, ['vi']);
        $en = app(TopicListQuery::class)->summary(self::SITE_A, ['en']);
        $viRows = collect(app(TopicListQuery::class)->paginate(self::SITE_A, ['per_page' => 50], ['vi'])->items())
            ->pluck('name')
            ->all();
        $enRows = collect(app(TopicListQuery::class)->paginate(self::SITE_A, ['per_page' => 50], ['en'])->items())
            ->pluck('name')
            ->all();

        self::assertSame(40, $vi['inventory_total']);
        self::assertSame(60, $en['inventory_total']);
        self::assertSame(['Mixed Topic', 'VI Bags'], collect($viRows)->sort()->values()->all());
        self::assertSame(['EN Laptops', 'Mixed Topic'], collect($enRows)->sort()->values()->all());
        self::assertSame(2, $vi['topic_count']);
        self::assertSame(2, $en['topic_count']);
        self::assertNotEquals($viRows, $enRows);
    }

    public function test_dictionary_rows_and_all_inventory_counters_share_the_same_language_ids(): void
    {
        $dictionary = app(KeywordDictionaryQuery::class);
        $inventory = app(KeywordUiInventoryQuery::class);
        $assignmentStats = app(KeywordTopicAssignmentStats::class);

        foreach ([['vi'], ['en']] as $variants) {
            $visibleRowIds = $dictionary->keywordIds(self::SITE_A, $variants);
            $counterInventoryIds = $inventory->keywordIds(self::SITE_A, $variants);
            sort($visibleRowIds);
            sort($counterInventoryIds);

            $stats = $assignmentStats->forSite(self::SITE_A, $variants);

            self::assertSame($visibleRowIds, $counterInventoryIds);
            self::assertSame(count($visibleRowIds), $inventory->count(self::SITE_A, $variants));
            self::assertSame(count($visibleRowIds), $stats['inventory_total']);
            self::assertSame(
                count($visibleRowIds),
                $stats['assigned'] + $stats['unassigned'],
            );
        }
    }

    public function test_null_language_variants_keep_site_wide_topic_count_contract(): void
    {
        $stats = app(KeywordTopicAssignmentStats::class)->forSite(self::SITE_A, null);

        self::assertSame(100, $stats['inventory_total']);
        self::assertSame(4, $stats['assigned']);
        self::assertSame(96, $stats['unassigned']);
        self::assertSame(3, $stats['topic_count']);
    }

    public function test_visible_option_never_resolves_to_unscoped_all_languages(): void
    {
        $options = ['vi' => 'Tiếng Việt', 'en' => 'English'];

        self::assertSame('vi', KeywordWorkspaceLanguageScope::resolveSelectedCode('vi_VN', $options, 'en'));
        self::assertSame('en', KeywordWorkspaceLanguageScope::resolveSelectedCode('stale', $options, 'en'));
        self::assertSame('vi', KeywordWorkspaceLanguageScope::resolveSelectedCode(null, $options, null));
    }

    public function test_language_match_from_another_site_cannot_leak_into_selected_site(): void
    {
        $siteAEnglish = $this->createArticle(self::SITE_A, 'en', 'Shared phrase EN');
        $normalVietnamese = $this->createArticle(self::SITE_NORMAL, 'vi', 'Shared phrase VI');
        $keywordId = $this->createInventoryKeyword('shared cross site phrase', $siteAEnglish, withFocus: false);
        $this->linkKeywordToArticle($keywordId, $normalVietnamese, 'shared cross site phrase');

        $inventory = app(KeywordUiInventoryQuery::class);

        self::assertSame(40, $inventory->count(self::SITE_A, ['vi']));
        self::assertSame(61, $inventory->count(self::SITE_A, ['en']));
    }

    public function test_ai_audit_selected_language_overrides_site_primary_for_multilingual_options(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/Topic/TopicalMapAuditService.php');

        self::assertStringContainsString('resolveEffectivePromptLanguage', $src);
        self::assertStringContainsString('formLanguageOptions', $src);
        self::assertStringContainsString('resolvePrimaryLanguage', $src);
        self::assertMatchesRegularExpression(
            '/\$explicit\s*=\s*trim\(\(string\)\s*\(\$languageCode\s*\?\?\s*\'\'\)\);/',
            $src,
        );
        self::assertStringContainsString('isset($options[$explicit])', $src);
        // Explicit valid selection wins before primary fallback.
        $explicitPos = strpos($src, 'isset($options[$explicit])');
        $primaryPos = strpos($src, 'resolvePrimaryLanguage($site)');
        self::assertNotFalse($explicitPos);
        self::assertNotFalse($primaryPos);
        self::assertLessThan($primaryPos, $explicitPos);
    }

    public function test_ai_audit_normal_site_falls_back_to_primary_when_no_selected_language(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/Topic/TopicalMapAuditService.php');
        $trait = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/RunsTopicalMapAuditAndTags.php');

        // Keywords Topics passes selector; Topical Map controller keeps null → primary.
        self::assertStringContainsString("\$selectedLanguage !== '' ? \$selectedLanguage : null", $trait);
        self::assertStringContainsString('?string $languageCode = null', $src);
        self::assertStringContainsString('resolvePrimaryLanguage($site)', $src);
    }

    public function test_wiring_passes_language_into_topic_paginate_and_audit(): void
    {
        $clusters = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');
        $trait = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/RunsTopicalMapAuditAndTags.php');
        $langTrait = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/Concerns/InteractsWithKeywordWorkspaceLanguageFilter.php');
        $listQuery = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/Topic/TopicListQuery.php');
        $audit = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/Topic/TopicalMapAuditService.php');

        self::assertStringContainsString('resolveKeywordLanguageFilterVariants()', $clusters);
        self::assertStringContainsString('->paginate($siteId,', $clusters);
        self::assertStringContainsString('keywordLanguageFilter', $trait);
        self::assertStringContainsString('->audit(', $trait);
        self::assertStringContainsString('clearKeywordWorkspaceTabCountsCache', $langTrait);
        self::assertStringContainsString('refreshAiAuditSnapshot', $langTrait);
        self::assertStringContainsString('?array $languageVariants = null', $listQuery);
        self::assertStringContainsString('resolveEffectivePromptLanguage', $audit);
    }

    /**
     * Site A: default VI, supported VI+EN with clearly separated landscapes.
     */
    private function seedMultilingualLandscape(): void
    {
        $viArticle = $this->createArticle(self::SITE_A, 'vi', 'VI focus article');
        $enArticle = $this->createArticle(self::SITE_A, 'en', 'EN focus article');
        $viTopic = $this->createTopic(self::SITE_A, 'VI Bags');
        $enTopic = $this->createTopic(self::SITE_A, 'EN Laptops');
        $mixedTopic = $this->createTopic(self::SITE_A, 'Mixed Topic');

        for ($index = 1; $index <= 40; $index++) {
            $keywordId = $this->createInventoryKeyword(
                "vietnamese inventory phrase {$index}",
                $viArticle,
                withFocus: $index <= 10,
            );
            if ($index === 1) {
                $this->assignTopicKeyword(self::SITE_A, $viTopic, $keywordId);
            } elseif ($index === 2) {
                $this->assignTopicKeyword(self::SITE_A, $mixedTopic, $keywordId);
            }
        }

        for ($index = 1; $index <= 60; $index++) {
            $keywordId = $this->createInventoryKeyword(
                "english inventory phrase {$index}",
                $enArticle,
                withFocus: $index <= 15,
            );
            if ($index === 1) {
                $this->assignTopicKeyword(self::SITE_A, $enTopic, $keywordId);
            } elseif ($index === 2) {
                $this->assignTopicKeyword(self::SITE_A, $mixedTopic, $keywordId);
            }
        }

        // Normal single-language site fixture (inventory only) for regression isolation.
        $normalArticle = $this->createArticle(self::SITE_NORMAL, 'vi', 'Normal site article');
        $this->createInventoryKeyword('normal site phrase', $normalArticle, withFocus: true);
    }

    private function linkKeywordToArticle(int $keywordId, int $sourceArticleId, string $phrase): void
    {
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $keywordId,
            'source_article_id' => $sourceArticleId,
            'target_article_id' => $sourceArticleId,
            'anchor_text' => $phrase,
            'link_type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTopic(int $siteId, string $name): int
    {
        return (int) DB::connection('omi_seo_ai')->table('seo_topics')->insertGetId([
            'site_id' => $siteId,
            'name' => $name,
            'source' => 'auto',
            'status' => 'active',
            'is_locked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignTopicKeyword(int $siteId, int $topicId, int $keywordId): void
    {
        DB::connection('omi_seo_ai')->table('seo_topic_keywords')->insert([
            'site_id' => $siteId,
            'topic_id' => $topicId,
            'keyword_id' => $keywordId,
            'source' => 'auto',
            'is_seed' => 0,
            'is_locked' => 0,
            'confidence' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        Schema::connection('omi_seo_ai')->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('name');
            $table->string('source')->default('auto');
            $table->string('status')->default('active');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_topic_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('topic_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('source')->default('auto');
            $table->boolean('is_seed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->float('confidence')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_site_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->boolean('is_seo_keyword')->default(true);
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_topic_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('topic_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }
}
