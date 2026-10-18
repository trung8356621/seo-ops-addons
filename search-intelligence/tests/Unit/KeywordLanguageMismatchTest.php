<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordQuality\KeywordQualityService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSiteKeywordService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordLanguageMismatchDetector;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityClassifier;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\KeywordQualityCode;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality\PolylangSiteContentLanguageCatalog;
use Tests\TestCase;

final class KeywordLanguageMismatchTest extends TestCase
{
    private const SITE_A = 41;

    private const SITE_B = 42;

    private \ArrayObject $languages;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('omi_seo_ai');
        $this->ensureTables();
        (require dirname(__DIR__, 2).'/database/migrations/2026_10_15_120000_create_seo_keyword_quality_tables.php')->up();
        $this->languages = new \ArrayObject();
        $this->bindLanguages();
    }

    public function test_detector_fixtures_avoid_short_brand_acronym_and_mixed(): void
    {
        $detector = new KeywordLanguageMismatchDetector;
        $before = memory_get_usage();
        self::assertSame(KeywordLanguageMismatchDetector::MISMATCH, $detector->verdict('the best running shoes for women', 'vi'));
        self::assertSame(KeywordLanguageMismatchDetector::MATCH, $detector->verdict('the best running shoes for women', 'en'));
        self::assertSame(KeywordLanguageMismatchDetector::MISMATCH, $detector->verdict('giày chạy bộ cho nữ', 'en'));
        self::assertSame(KeywordLanguageMismatchDetector::MATCH, $detector->verdict('giày chạy bộ cho nữ', 'vi'));
        foreach ([
            'Nike',
            'SEO',
            'CRM',
            'USB',
            'iPhone 15 Pro',
            'balo laptop',
            'túi tote bag',
            'balo laptop cho học sinh',
            'SEO CRM B2B API',
        ] as $phrase) {
            self::assertSame(KeywordLanguageMismatchDetector::UNCERTAIN, $detector->verdict($phrase, 'vi'), $phrase);
        }
        for ($i = 0; $i < 1000; $i++) {
            $detector->verdict('the best running shoes for women', 'vi');
        }
        self::assertLessThan(1_048_576, memory_get_usage() - $before);
    }

    public function test_monolingual_site_does_not_tag_foreign_keywords(): void
    {
        $this->languages[self::SITE_A] = ['vi'];
        $id = $this->keyword('the best running shoes for women', self::SITE_A, 'vi');

        $service = $this->service();
        $service->scanPhrases(self::SITE_A, [$id => 'the best running shoes for women']);

        self::assertNotContains(KeywordQualityCode::LANGUAGE_MISMATCH, $service->codesFor(self::SITE_A, $id));
    }

    public function test_bilingual_site_tags_confident_english_inside_vietnamese_inventory(): void
    {
        $this->languages[self::SITE_A] = ['vi', 'en'];
        $id = $this->keyword('the best running shoes for women', self::SITE_A, 'vi');

        $codes = $this->scan(self::SITE_A, [$id => 'the best running shoes for women'], $id);

        self::assertContains(KeywordQualityCode::LANGUAGE_MISMATCH, $codes);
        self::assertFalse($this->service()->isExcluded(self::SITE_A, KeywordQualityCode::LANGUAGE_MISMATCH));
    }

    public function test_english_inventory_keeps_english_keywords(): void
    {
        $this->languages[self::SITE_A] = ['vi', 'en'];
        $id = $this->keyword('the best running shoes for women', self::SITE_A, 'en');

        $codes = $this->scan(self::SITE_A, [$id => 'the best running shoes for women'], $id);

        self::assertNotContains(KeywordQualityCode::LANGUAGE_MISMATCH, $codes);
    }

    public function test_uncertain_phrases_are_not_tagged(): void
    {
        $this->languages[self::SITE_A] = ['vi', 'en'];
        $phrases = [
            'Nike' => 'vi',
            'SEO' => 'vi',
            'USB' => 'vi',
            'balo laptop cho học sinh' => 'vi',
            'túi tote bag' => 'vi',
        ];
        $map = [];
        foreach ($phrases as $phrase => $language) {
            $id = $this->keyword($phrase, self::SITE_A, $language);
            $map[$id] = $phrase;
        }

        $service = $this->service();
        $service->scanPhrases(self::SITE_A, $map);

        foreach (array_keys($map) as $id) {
            self::assertNotContains(KeywordQualityCode::LANGUAGE_MISMATCH, $service->codesFor(self::SITE_A, $id));
        }
    }

    public function test_dropping_to_one_language_clears_auto_mismatch_tags(): void
    {
        $this->languages[self::SITE_A] = ['vi', 'en'];
        $id = $this->keyword('the best running shoes for women', self::SITE_A, 'vi');
        $service = $this->service();
        $service->scanPhrases(self::SITE_A, [$id => 'the best running shoes for women']);
        self::assertContains(KeywordQualityCode::LANGUAGE_MISMATCH, $service->codesFor(self::SITE_A, $id));

        $this->languages[self::SITE_A] = ['vi'];
        $service->scanPhrases(self::SITE_A, [$id => 'the best running shoes for women']);

        self::assertNotContains(KeywordQualityCode::LANGUAGE_MISMATCH, $service->codesFor(self::SITE_A, $id));
    }

    public function test_repeat_scan_does_not_duplicate_tags(): void
    {
        $this->languages[self::SITE_A] = ['vi', 'en'];
        $id = $this->keyword('the best running shoes for women', self::SITE_A, 'vi');
        $service = $this->service();
        $phrases = [$id => 'the best running shoes for women'];
        $service->scanPhrases(self::SITE_A, $phrases);
        $service->scanPhrases(self::SITE_A, $phrases);

        self::assertSame(1, DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags')
            ->where('site_id', self::SITE_A)
            ->where('keyword_id', $id)
            ->where('code', KeywordQualityCode::LANGUAGE_MISMATCH)
            ->count());
    }

    public function test_existing_quality_tags_stay_unchanged(): void
    {
        $classifier = new KeywordQualityClassifier;
        self::assertSame([KeywordQualityCode::CTA_ANCHOR], $classifier->classify('mua ngay'));
        self::assertSame([KeywordQualityCode::GENERIC_ANCHOR], $classifier->classify('tại đây'));
        self::assertSame([], $classifier->classify('balo học sinh'));
        self::assertNotContains(KeywordQualityCode::LANGUAGE_MISMATCH, $classifier->classify('the best running shoes for women'));

        $this->languages[self::SITE_A] = ['vi', 'en'];
        $cta = $this->keyword('mua ngay', self::SITE_A, 'vi');
        $english = $this->keyword('click here to buy the best shoes', self::SITE_A, 'vi');
        $service = $this->service();
        $service->scanPhrases(self::SITE_A, [
            $cta => 'mua ngay',
            $english => 'click here to buy the best shoes',
        ]);

        self::assertSame([KeywordQualityCode::CTA_ANCHOR], $service->codesFor(self::SITE_A, $cta));
        $englishCodes = $service->codesFor(self::SITE_A, $english);
        self::assertContains(KeywordQualityCode::CTA_ANCHOR, $englishCodes);
        self::assertContains(KeywordQualityCode::LANGUAGE_MISMATCH, $englishCodes);
    }

    public function test_scan_does_not_change_groups_topics_or_keyword_text(): void
    {
        $this->languages[self::SITE_A] = ['vi', 'en'];
        $id = $this->keyword('the best running shoes for women', self::SITE_A, 'vi');
        $this->membership(self::SITE_A, 7, $id, true);
        $schema = Schema::connection('omi_seo_ai');
        $schema->create('seo_keyword_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->string('source');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });
        $schema->create('seo_keyword_group_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('source');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });
        $now = now();
        DB::connection('omi_seo_ai')->table('seo_keyword_groups')->insert([
            'id' => 3,
            'site_id' => self::SITE_A,
            'name' => 'Manual shoes',
            'source' => 'manual',
            'is_locked' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::connection('omi_seo_ai')->table('seo_keyword_group_keywords')->insert([
            'site_id' => self::SITE_A,
            'group_id' => 3,
            'keyword_id' => $id,
            'source' => 'manual',
            'is_locked' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = $this->service();
        $service->scanPhrases(self::SITE_A, [$id => 'the best running shoes for women']);

        self::assertSame('the best running shoes for women', (string) Keyword::query()->whereKey($id)->value('phrase'));
        self::assertSame('vi', (string) DB::connection('omi_seo_ai')->table('articles')->where('site_id', self::SITE_A)->value('language'));
        self::assertSame(1, SeoTopicKeyword::query()->where('keyword_id', $id)->where('is_locked', 1)->count());
        self::assertSame(7, app(TopicMembershipQuery::class)->topicIdsByKeywordId(self::SITE_A, [$id])[$id]);
        self::assertContains($id, array_column(app(TopicSiteKeywordService::class)->loadTopicCandidateKeywords(self::SITE_A), 'keyword_id'));
        self::assertSame('manual', (string) DB::connection('omi_seo_ai')->table('seo_keyword_groups')->value('source'));
        self::assertSame(1, (int) DB::connection('omi_seo_ai')->table('seo_keyword_groups')->value('is_locked'));
        self::assertSame(1, DB::connection('omi_seo_ai')->table('seo_keyword_group_keywords')->where('keyword_id', $id)->count());
        self::assertSame(0, (int) DB::connection('omi_seo_ai')->table('seo_keyword_quality_policies')
            ->where('code', KeywordQualityCode::LANGUAGE_MISMATCH)
            ->count());
    }

    public function test_tags_stay_inside_site_and_language_inventory(): void
    {
        $this->languages[self::SITE_A] = ['vi', 'en'];
        $this->languages[self::SITE_B] = ['vi', 'en'];
        $mismatched = $this->keyword('the best running shoes for women', self::SITE_A, 'vi');
        $matched = $this->keyword('the best running shoes for women', self::SITE_B, 'en');
        $service = $this->service();
        $service->scanPhrases(self::SITE_A, [$mismatched => 'the best running shoes for women']);
        $service->scanPhrases(self::SITE_B, [$matched => 'the best running shoes for women']);

        self::assertContains(KeywordQualityCode::LANGUAGE_MISMATCH, $service->codesFor(self::SITE_A, $mismatched));
        self::assertSame([], $service->codesFor(self::SITE_B, $mismatched));
        self::assertNotContains(KeywordQualityCode::LANGUAGE_MISMATCH, $service->codesFor(self::SITE_B, $matched));
        self::assertSame(0, DB::connection('omi_seo_ai')->table('seo_keyword_quality_tags')
            ->where('site_id', self::SITE_B)
            ->where('code', KeywordQualityCode::LANGUAGE_MISMATCH)
            ->count());
    }

    private function bindLanguages(): void
    {
        $state = $this->languages;
        $this->app->instance(PolylangSiteContentLanguageCatalog::class, new class($state) extends PolylangSiteContentLanguageCatalog {
            public function __construct(private \ArrayObject $state) {}

            public function configuredCodes(int $siteId): array
            {
                $codes = $this->state[$siteId] ?? ['vi'];

                return is_array($codes) ? $codes : ['vi'];
            }
        });
    }

    private function service(): KeywordQualityService
    {
        return app(KeywordQualityService::class);
    }

    /**
     * @param  array<int, string>  $phrases
     * @return list<string>
     */
    private function scan(int $siteId, array $phrases, int $keywordId): array
    {
        $service = $this->service();
        $service->scanPhrases($siteId, $phrases);

        return $service->codesFor($siteId, $keywordId);
    }

    private function keyword(string $phrase, int $siteId, ?string $language): int
    {
        $id = (int) Keyword::query()->create([
            'phrase' => $phrase,
            'type' => 'normal',
            'review_status' => 'active',
        ])->id;
        DB::connection('omi_seo_ai')->table('articles')->insert([
            'id' => 1000 + $id,
            'site_id' => $siteId,
            'title' => 'Article '.$id,
            'language' => $language,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $id,
            'meta_key' => KeywordMetaKey::siteMainArticleId($siteId),
            'meta_value' => (string) (1000 + $id),
        ]);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'keyword_id' => $id,
            'source_article_id' => 1000 + $id,
            'status' => 'active',
        ]);

        return $id;
    }

    private function membership(int $siteId, int $topicId, int $keywordId, bool $locked): void
    {
        SeoTopicKeyword::query()->create([
            'site_id' => $siteId,
            'topic_id' => $topicId,
            'keyword_id' => $keywordId,
            'source' => 'auto',
            'is_seed' => false,
            'is_locked' => $locked,
        ]);
    }

    private function ensureTables(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->string('review_status')->default('active');
            $table->timestamps();
        });
        $schema->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('title')->nullable();
            $table->string('language')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
        $schema->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->nullable();
            $table->unsignedBigInteger('source_article_id')->nullable();
            $table->string('status')->nullable();
        });
        $schema->create('seo_topic_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('source')->default('auto');
            $table->boolean('is_seed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->float('confidence')->nullable();
            $table->timestamps();
        });
    }
}
