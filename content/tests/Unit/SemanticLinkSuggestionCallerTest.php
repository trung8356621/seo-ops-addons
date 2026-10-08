<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ExternalWikiSuggestionService;
use Omnichannel\Addons\Content\Services\InternalLinkV2Suggester;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Tests\TestCase;

final class SemanticLinkSuggestionCallerTest extends TestCase
{
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
            'semantic.enabled' => true,
            'semantic.url' => 'http://semantic.test',
            'semantic.timeout' => 5,
        ]);
        DB::purge('omi_seo_ai');
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
            $table->softDeletes();
            $table->timestamps();
        });
        $schema->create('article_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->nullable();
            $table->unsignedBigInteger('source_article_id')->nullable();
            $table->unsignedBigInteger('target_article_id')->nullable();
            $table->string('link_type')->default('internal');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        $schema->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->timestamps();
        });
        $schema->create('publishing_article_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->string('publication_status')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        $migration = require dirname(__DIR__, 3).'/search-intelligence/database/migrations/2026_10_07_180000_create_seo_keyword_groups.php';
        $migration->up();
    }

    public function test_internal_link_v2_sends_group_candidates_and_real_inbound_counts(): void
    {
        $source = $this->article(9, 'Bài nguồn');
        $heavy = $this->article(9, 'Bài cotton cũ');
        $fresh = $this->article(9, 'Bài cotton mới');
        $this->permalink($heavy, 'https://example.test/old');
        $this->permalink($fresh, 'https://example.test/new');
        DB::connection('omi_seo_ai')->table('publishing_article_states')->insert([
            'article_id' => $fresh,
            'publication_status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keyword = Keyword::query()->create(['phrase' => 'áo thun cotton', 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keyword->id,
            'meta_key' => KeywordMetaKey::siteMainArticleId(9),
            'meta_value' => (string) $fresh,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => 9,
            'name' => 'Cotton',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'tg:cotton',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 9,
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'source_article_id' => $source,
            'target_article_id' => $heavy,
            'link_type' => 'internal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'http://semantic.test/v1/internal-links/v2/rank' => function ($request) use ($fresh) {
                $payload = $request->data();
                TestCase::assertSame('topic_group', $payload['candidate_boundary']);
                TestCase::assertNotSame('', $payload['source_text']);
                TestCase::assertSame('article:'.$fresh, $payload['candidates'][0]['ref']);
                TestCase::assertSame(0, $payload['candidates'][0]['inbound_count']);

                return Http::response([
                    'source_ref' => 'article:1',
                    'candidate_boundary' => 'topic_group',
                    'suggestions' => [[
                        'ref' => 'article:'.$fresh,
                        'topic_group_ref' => 'tg:cotton',
                        'url' => 'https://example.test/new',
                        'score' => 0.7,
                        'components' => ['relevance' => 0.8, 'overuse_penalty' => 0, 'underlinked_bonus' => 0.18, 'repetition_penalty' => 0, 'final_score' => 0.7],
                    ]],
                    'rejected' => [],
                    'metrics' => ['articles_with_zero_inbound' => 1],
                ]);
            },
        ]);

        $article = SeoArticle::query()->findOrFail($source);
        $result = (new InternalLinkV2Suggester())->suggest($article, '<p>Mẫu áo thun cotton cho mùa này.</p>');

        self::assertSame('ok', $result['status']);
        self::assertSame('áo thun cotton', $result['suggestions'][0]['text']);
        self::assertSame('internal_link_v2', $result['suggestions'][0]['source']);
        self::assertSame($fresh, $result['suggestions'][0]['target_article_id']);
        self::assertTrue(str_contains('Mẫu áo thun cotton cho mùa này.', $result['suggestions'][0]['text']));
    }

    public function test_wordpress_publish_status_is_eligible_without_published_at(): void
    {
        $source = $this->article(9, 'Bài nguồn');
        $target = $this->article(9, 'Bài đã xuất bản trên WordPress');
        $this->permalink($target, 'https://example.test/wp');
        DB::connection('omi_seo_ai')->table('publishing_article_states')->insert([
            'article_id' => $target,
            'publication_status' => 'publish',
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keyword = Keyword::query()->create(['phrase' => 'balo học sinh', 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keyword->id,
            'meta_key' => KeywordMetaKey::siteMainArticleId(9),
            'meta_value' => (string) $target,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => 9,
            'name' => 'Balo',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'tg:balo',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 9,
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        Http::fake([
            'http://semantic.test/v1/internal-links/v2/rank' => function ($request) use ($target) {
                TestCase::assertSame('article:'.$target, $request->data()['candidates'][0]['ref']);

                return Http::response([
                    'suggestions' => [[
                        'ref' => 'article:'.$target,
                        'score' => 0.6,
                        'components' => ['relevance' => 0.6],
                    ]],
                    'metrics' => [],
                ]);
            },
        ]);

        $result = (new InternalLinkV2Suggester())->suggest(
            SeoArticle::query()->findOrFail($source),
            '<p>balo học sinh đi học mỗi ngày.</p>',
        );

        self::assertSame('ok', $result['status']);
        self::assertSame($target, $result['suggestions'][0]['target_article_id']);
    }

    public function test_empty_editor_content_returns_explicit_reason(): void
    {
        $article = new SeoArticle();
        $article->id = 1;
        $result = (new InternalLinkV2Suggester())->suggest($article, '   ');

        self::assertSame('empty', $result['status']);
        self::assertSame('content_unavailable', $result['reason']);
        self::assertSame([], $result['suggestions']);
    }

    public function test_wiki_caller_keeps_only_verified_urls_present_in_the_article(): void
    {
        Http::fake([
            'http://semantic.test/v1/wiki-suggestions' => Http::response([
                'article_ref' => 'article:1',
                'suggestions' => [
                    ['ref' => 'wiki:rfid', 'term' => 'RFID', 'url' => 'https://en.wikipedia.org/wiki/Radio-frequency_identification', 'score' => 1, 'evidence' => 'canonical_alias'],
                    ['ref' => 'wiki:bad', 'term' => 'RFID', 'url' => 'https://evil.test/rfid', 'score' => 1, 'evidence' => 'canonical_alias'],
                    ['ref' => 'wiki:missing', 'term' => 'OEM', 'url' => 'https://en.wikipedia.org/wiki/Original_equipment_manufacturer', 'score' => 1, 'evidence' => 'canonical_alias'],
                ],
                'rejected_terms' => ['khách hàng'],
            ]),
        ]);
        $article = new SeoArticle();
        $article->id = 1;
        $article->language = 'vi';
        $result = (new ExternalWikiSuggestionService())->suggest($article, 'Kho dùng RFID để quét hàng.');

        self::assertCount(1, $result['suggestions']);
        self::assertSame('RFID', $result['suggestions'][0]['text']);
        self::assertStringStartsWith('https://en.wikipedia.org/wiki/', $result['suggestions'][0]['href']);
        self::assertTrue($result['suggestions'][0]['is_suggestion']);
    }

    private function article(int $siteId, string $title): int
    {
        return (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => $siteId,
            'title' => $title,
            'language' => 'vi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function permalink(int $articleId, string $url): void
    {
        DB::connection('omi_seo_ai')->table('article_meta')->insert([
            'article_id' => $articleId,
            'meta_key' => 'wp_permalink',
            'meta_value' => $url,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
