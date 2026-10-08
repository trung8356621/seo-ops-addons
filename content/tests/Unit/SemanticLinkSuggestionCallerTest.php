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
        $schema->create('seo_article_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->decimal('seo_score', 5, 2)->nullable();
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
        $this->attachArticleToGroup($source, 9, $group->id, 'bài nguồn cotton');
        DB::connection('omi_seo_ai')->table('seo_link_maps')->insert([
            'source_article_id' => $source,
            'target_article_id' => $heavy,
            'link_type' => 'internal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => Http::response([
                'scope_ref' => 'site:9',
                'matches' => [['ref' => 'tg:cotton', 'score' => 0.71]],
            ]),
            'http://semantic.test/v1/internal-links/v2/rank' => function ($request) use ($fresh) {
                $payload = $request->data();
                TestCase::assertSame('topic_group', $payload['candidate_boundary']);
                TestCase::assertNotSame('', $payload['source_text']);
                TestCase::assertSame('article:'.$fresh, $payload['candidates'][0]['ref']);
                TestCase::assertSame(0, $payload['candidates'][0]['inbound_count']);
                TestCase::assertArrayNotHasKey('relevance', $payload['candidates'][0]);
                TestCase::assertSame('Bài cotton mới áo thun cotton', $payload['candidates'][0]['representation']);

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
        $this->attachArticleToGroup($source, 9, $group->id, 'bài nguồn balo');
        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => Http::response([
                'scope_ref' => 'site:9',
                'matches' => [['ref' => 'tg:balo', 'score' => 0.66]],
            ]),
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

    public function test_related_target_is_found_without_its_keyword_phrase_in_the_source(): void
    {
        $source = $this->article(9, 'Bài nguồn');
        $target = $this->article(9, 'Áo thun cotton organic');
        $this->permalink($target, 'https://example.test/organic');
        DB::connection('omi_seo_ai')->table('publishing_article_states')->insert([
            'article_id' => $target,
            'publication_status' => 'publish',
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keyword = Keyword::query()->create(['phrase' => 'áo thun cotton organic', 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keyword->id,
            'meta_key' => KeywordMetaKey::siteMainArticleId(9),
            'meta_value' => (string) $target,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => 9,
            'name' => 'Cotton',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'tg:organic',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 9,
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        $this->attachArticleToGroup($source, 9, $group->id, 'nguồn');
        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => Http::response([
                'scope_ref' => 'site:9',
                'matches' => [['ref' => 'tg:organic', 'score' => 0.68]],
            ]),
            'http://semantic.test/v1/internal-links/v2/rank' => Http::response([
                'suggestions' => [[
                    'ref' => 'article:'.$target,
                    'score' => 0.66,
                    'components' => ['relevance' => 0.7],
                ]],
                'metrics' => [],
            ]),
        ]);

        $result = (new InternalLinkV2Suggester())->suggest(
            SeoArticle::query()->findOrFail($source),
            '<p>Mẫu áo thun cotton cho mùa này.</p>',
        );

        self::assertSame('ok', $result['status']);
        self::assertSame('áo thun cotton', mb_strtolower($result['suggestions'][0]['text']));
        self::assertSame('https://example.test/organic', $result['suggestions'][0]['href']);
        self::assertSame(1, $result['metrics']['stages']['topic_groups']);
    }

    public function test_other_site_group_cannot_supply_targets(): void
    {
        $source = $this->article(9, 'Bài nguồn');
        $foreign = $this->article(8, 'Áo thun cotton organic');
        $this->permalink($foreign, 'https://other.test/organic');
        DB::connection('omi_seo_ai')->table('publishing_article_states')->insert([
            'article_id' => $foreign,
            'publication_status' => 'publish',
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keyword = Keyword::query()->create(['phrase' => 'áo thun cotton organic', 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keyword->id,
            'meta_key' => KeywordMetaKey::siteMainArticleId(8),
            'meta_value' => (string) $foreign,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => 8,
            'name' => 'Foreign',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'tg:foreign',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 8,
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        Http::fake();

        $result = (new InternalLinkV2Suggester())->suggest(
            SeoArticle::query()->findOrFail($source),
            '<p>Mẫu áo thun cotton cho mùa này.</p>',
        );

        self::assertSame('no_topic_group', $result['reason']);
        self::assertSame([], $result['suggestions']);
        Http::assertNothingSent();
    }

    public function test_generic_anchor_is_not_suggested(): void
    {
        $source = $this->article(9, 'Bài nguồn');
        $target = $this->article(9, 'Chất liệu');
        $this->permalink($target, 'https://example.test/material');
        DB::connection('omi_seo_ai')->table('publishing_article_states')->insert([
            'article_id' => $target,
            'publication_status' => 'publish',
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keyword = Keyword::query()->create(['phrase' => 'chất liệu', 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keyword->id,
            'meta_key' => KeywordMetaKey::siteMainArticleId(9),
            'meta_value' => (string) $target,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => 9,
            'name' => 'Material',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'tg:material',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 9,
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        $this->attachArticleToGroup($source, 9, $group->id, 'nguồn chất liệu');
        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => Http::response([
                'scope_ref' => 'site:9',
                'matches' => [['ref' => 'tg:material', 'score' => 0.6]],
            ]),
            'http://semantic.test/v1/internal-links/v2/rank' => Http::response([
                'suggestions' => [[
                    'ref' => 'article:'.$target,
                    'score' => 0.4,
                    'components' => ['relevance' => 0.4],
                ]],
                'rejected' => [['ref' => 'article:'.$target, 'reason' => 'low_score']],
                'metrics' => [],
            ]),
        ]);

        $result = (new InternalLinkV2Suggester())->suggest(
            SeoArticle::query()->findOrFail($source),
            '<p>Chất liệu đẹp cho khách hàng.</p>',
        );

        self::assertSame([], $result['suggestions']);
        self::assertSame('anchor_quality', $result['reason']);
    }

    public function test_disabled_semantic_never_falls_back_to_legacy(): void
    {
        config(['semantic.enabled' => false]);
        Http::fake();
        $article = new SeoArticle();
        $article->id = 4;
        $article->site_id = 9;
        $result = (new InternalLinkV2Suggester())->suggest($article, '<p>Nội dung.</p>');

        self::assertSame('unavailable', $result['status']);
        self::assertSame('semantic_unavailable', $result['reason']);
        Http::assertNothingSent();
        $source = (string) file_get_contents((string) (new \ReflectionClass(InternalLinkV2Suggester::class))->getFileName());
        self::assertStringNotContainsString('ArticleInternalLinkSuggestionService', $source);
        self::assertStringNotContainsString('suggestBundle', $source);
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

    public function test_semantic_match_finds_targets_without_source_membership(): void
    {
        $source = $this->article(9, 'Bài không thuộc group');
        $target = $this->article(9, 'Balo học sinh');
        $this->permalink($target, 'https://example.test/balo');
        DB::connection('omi_seo_ai')->table('publishing_article_states')->insert([
            'article_id' => $target,
            'publication_status' => 'published',
            'published_at' => now(),
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
            'name' => 'Balo học sinh',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'g-0016',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 9,
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        $long = str_repeat('Nội dung balo đi học. ', 250).'balo học sinh';
        self::assertGreaterThan(4000, mb_strlen($long));
        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => function ($request) use ($long) {
                $payload = $request->data();
                TestCase::assertSame('site:9', $payload['scope_ref']);
                TestCase::assertGreaterThan(4000, mb_strlen((string) $payload['query']));
                TestCase::assertSame('g-0016', $payload['groups'][0]['ref']);

                return Http::response([
                    'scope_ref' => 'site:9',
                    'matches' => [['ref' => 'g-0016', 'score' => 0.6758]],
                ]);
            },
            'http://semantic.test/v1/internal-links/v2/rank' => function ($request) use ($target) {
                $payload = $request->data();
                TestCase::assertGreaterThan(4000, mb_strlen((string) $payload['source_text']));
                TestCase::assertLessThanOrEqual(65536, mb_strlen((string) $payload['source_text']));
                TestCase::assertArrayNotHasKey('relevance', $payload['candidates'][0]);
                TestCase::assertStringContainsString('balo học sinh', (string) $payload['candidates'][0]['representation']);

                return Http::response([
                    'suggestions' => [[
                        'ref' => 'article:'.$target,
                        'score' => 0.67,
                        'components' => ['relevance' => 0.67],
                    ]],
                    'rejected' => [],
                    'metrics' => [],
                ]);
            },
        ]);

        $result = (new InternalLinkV2Suggester())->suggest(SeoArticle::query()->findOrFail($source), $long);

        self::assertSame('ok', $result['status']);
        self::assertSame('balo học sinh', mb_strtolower($result['suggestions'][0]['text']));
        self::assertSame('https://example.test/balo', $result['suggestions'][0]['href']);
        self::assertSame(1, $result['metrics']['stages']['semantic_groups']);
        self::assertStringContainsString($result['suggestions'][0]['text'], $long);
    }

    public function test_direct_membership_alone_does_not_discover_a_group(): void
    {
        $source = $this->article(9, 'Bài nguồn');
        $target = $this->article(9, 'Áo thun cotton organic');
        $this->permalink($target, 'https://example.test/organic');
        DB::connection('omi_seo_ai')->table('publishing_article_states')->insert([
            'article_id' => $target,
            'publication_status' => 'publish',
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keyword = Keyword::query()->create(['phrase' => 'áo thun cotton organic', 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keyword->id,
            'meta_key' => KeywordMetaKey::siteMainArticleId(9),
            'meta_value' => (string) $target,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => 9,
            'name' => 'Cotton',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'tg:organic',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 9,
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        $this->attachArticleToGroup($source, 9, $group->id, 'nguồn');
        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => Http::response([
                'scope_ref' => 'site:9',
                'matches' => [],
            ]),
        ]);

        $result = (new InternalLinkV2Suggester())->suggest(
            SeoArticle::query()->findOrFail($source),
            '<p>Mẫu áo thun cotton cho mùa này.</p>',
        );

        self::assertSame('no_topic_group', $result['reason']);
        self::assertSame([], $result['suggestions']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/internal-links/v2/rank'));
    }

    public function test_semantic_matcher_failure_does_not_fall_back(): void
    {
        $source = $this->article(9, 'Bài nguồn');
        $group = SeoKeywordGroup::query()->create([
            'site_id' => 9,
            'name' => 'Cotton',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'tg:cotton',
            'is_locked' => false,
        ]);
        $keyword = Keyword::query()->create(['phrase' => 'áo thun cotton', 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 9,
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => Http::response(['detail' => 'down'], 503),
        ]);

        $result = (new InternalLinkV2Suggester())->suggest(
            SeoArticle::query()->findOrFail($source),
            '<p>Mẫu áo thun cotton cho mùa này.</p>',
        );

        self::assertSame('unavailable', $result['status']);
        self::assertSame('semantic_unavailable', $result['reason']);
        self::assertSame([], $result['suggestions']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/internal-links/v2/rank'));
        $sourceFile = (string) file_get_contents((string) (new \ReflectionClass(InternalLinkV2Suggester::class))->getFileName());
        self::assertStringNotContainsString('Cache::', $sourceFile);
        self::assertStringNotContainsString('mb_substr($plain, 0, 4000)', $sourceFile);
    }

    public function test_article_site_does_not_read_another_sites_groups(): void
    {
        $source = $this->article(6, 'Bài site 6');
        $foreign = $this->article(4, 'Balo site 4');
        $local = $this->article(6, 'Balo site 6');
        $this->permalink($foreign, 'https://site4.test/balo');
        $this->permalink($local, 'https://site6.test/balo');
        DB::connection('omi_seo_ai')->table('publishing_article_states')->insert([
            ['article_id' => $foreign, 'publication_status' => 'publish', 'published_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['article_id' => $local, 'publication_status' => 'publish', 'published_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $foreignKeyword = Keyword::query()->create(['phrase' => 'balo học sinh', 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        $localKeyword = Keyword::query()->create(['phrase' => 'balo site sáu', 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            ['keyword_id' => $foreignKeyword->id, 'meta_key' => KeywordMetaKey::siteMainArticleId(4), 'meta_value' => (string) $foreign, 'created_at' => now(), 'updated_at' => now()],
            ['keyword_id' => $localKeyword->id, 'meta_key' => KeywordMetaKey::siteMainArticleId(6), 'meta_value' => (string) $local, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $foreignGroup = SeoKeywordGroup::query()->create([
            'site_id' => 4,
            'name' => 'Balo site 4',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'g-site4',
            'is_locked' => false,
        ]);
        $localGroup = SeoKeywordGroup::query()->create([
            'site_id' => 6,
            'name' => 'Balo site 6',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'g-site6',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 4,
            'group_id' => $foreignGroup->id,
            'keyword_id' => $foreignKeyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 6,
            'group_id' => $localGroup->id,
            'keyword_id' => $localKeyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => function ($request) {
                $refs = array_column($request->data()['groups'], 'ref');
                TestCase::assertSame(['g-site6'], $refs);
                TestCase::assertSame('site:6', $request->data()['scope_ref']);

                return Http::response([
                    'scope_ref' => 'site:6',
                    'matches' => [['ref' => 'g-site6', 'score' => 0.7]],
                ]);
            },
            'http://semantic.test/v1/internal-links/v2/rank' => Http::response([
                'suggestions' => [[
                    'ref' => 'article:'.$local,
                    'score' => 0.7,
                    'components' => ['relevance' => 0.7],
                ]],
                'metrics' => [],
            ]),
        ]);

        $result = (new InternalLinkV2Suggester())->suggest(
            SeoArticle::query()->findOrFail($source),
            '<p>balo site sáu cho học sinh.</p>',
        );

        self::assertSame('ok', $result['status']);
        self::assertSame($local, $result['suggestions'][0]['target_article_id']);
        self::assertSame('https://site6.test/balo', $result['suggestions'][0]['href']);
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

    private function attachArticleToGroup(int $articleId, int $siteId, int $groupId, string $phrase): void
    {
        $keyword = Keyword::query()->create(['phrase' => $phrase, 'type' => Keyword::TYPE_NORMAL, 'review_status' => 'active']);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            'keyword_id' => $keyword->id,
            'meta_key' => KeywordMetaKey::siteMainArticleId($siteId),
            'meta_value' => (string) $articleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => $siteId,
            'group_id' => $groupId,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
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
