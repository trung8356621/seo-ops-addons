<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Semantic;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup\TopicGroupArticleRetriever;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\TopicGroup\TopicGroupRetrievalClient;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Tests\TestCase;

final class TopicGroupArticleRetrieverTest extends TestCase
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
            'semantic.url' => 'http://semantic.test',
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
            $table->softDeletes();
            $table->timestamps();
        });
        $schema->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
        $schema->create('seo_article_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->decimal('seo_score', 5, 2)->nullable();
            $table->timestamps();
        });
        $schema->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->timestamps();
        });
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_07_180000_create_seo_keyword_groups.php';
        $migration->up();
    }

    public function test_match_resolves_keywords_articles_and_score_inside_the_site(): void
    {
        $article = (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => 9,
            'title' => 'Áo thun cotton',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherArticle = (int) DB::connection('omi_seo_ai')->table('articles')->insertGetId([
            'site_id' => 10,
            'title' => 'Site khác',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('seo_article_profiles')->insert([
            'article_id' => $article,
            'seo_score' => 41.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keyword = Keyword::query()->create([
            'phrase' => 'áo thun cotton',
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => 'active',
        ]);
        $otherKeyword = Keyword::query()->create([
            'phrase' => 'máy lọc nước',
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => 'active',
        ]);
        DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
            ['keyword_id' => $keyword->id, 'meta_key' => KeywordMetaKey::siteMainArticleId(9), 'meta_value' => (string) $article, 'created_at' => now(), 'updated_at' => now()],
            ['keyword_id' => $otherKeyword->id, 'meta_key' => KeywordMetaKey::siteMainArticleId(10), 'meta_value' => (string) $otherArticle, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $group = SeoKeywordGroup::query()->create([
            'site_id' => 9,
            'name' => 'Cotton',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'tg:cotton',
            'is_locked' => false,
        ]);
        $otherGroup = SeoKeywordGroup::query()->create([
            'site_id' => 10,
            'name' => 'Water',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'tg:water',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 9,
            'group_id' => $group->id,
            'keyword_id' => $keyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 10,
            'group_id' => $otherGroup->id,
            'keyword_id' => $otherKeyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);

        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => Http::response([
                'scope_ref' => 'site:9',
                'matches' => [
                    ['ref' => 'tg:cotton', 'score' => 0.91, 'evidence' => ['lexical_matched' => true]],
                    ['ref' => 'tg:water', 'score' => 0.2, 'evidence' => ['lexical_matched' => false]],
                ],
            ]),
        ]);

        $result = (new TopicGroupArticleRetriever(new TopicGroupRetrievalClient(new SemanticAnalyticsClient())))
            ->retrieve(9, 'áo thun cotton nam');

        self::assertSame('ok', $result['status']);
        self::assertSame('tg:cotton', $result['groups'][0]['ref']);
        self::assertSame('keyword:'.$keyword->id, $result['groups'][0]['keywords'][0]['ref']);
        self::assertSame('article:'.$article, $result['groups'][0]['keywords'][0]['article']['ref']);
        self::assertSame(9, $result['groups'][0]['keywords'][0]['article']['site_id']);
        self::assertSame(41.5, $result['groups'][0]['keywords'][0]['article']['seo_score']);
        self::assertCount(1, $result['groups']);
    }

    public function test_colliding_semantic_refs_are_sent_as_unique_keyword_group_ids(): void
    {
        $firstKeyword = Keyword::query()->create([
            'phrase' => 'máy phát điện',
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => 'active',
        ]);
        $secondKeyword = Keyword::query()->create([
            'phrase' => 'máy phát điện cũ',
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => 'active',
        ]);
        $first = SeoKeywordGroup::query()->create([
            'site_id' => 6,
            'name' => 'Legacy',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'g-0004',
            'is_locked' => false,
        ]);
        $second = SeoKeywordGroup::query()->create([
            'site_id' => 6,
            'name' => 'English',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'g-0004',
            'is_locked' => false,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 6,
            'group_id' => $first->id,
            'keyword_id' => $firstKeyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);
        SeoKeywordGroupKeyword::query()->create([
            'site_id' => 6,
            'group_id' => $second->id,
            'keyword_id' => $secondKeyword->id,
            'source' => KeywordGroupSource::SEMANTIC,
        ]);

        Http::fake([
            'http://semantic.test/v1/topic-groups/matches' => Http::response([
                'scope_ref' => 'site:6',
                'matches' => [
                    ['ref' => 'keyword-group:'.$first->id, 'score' => 0.8, 'evidence' => ['lexical_matched' => false]],
                    ['ref' => 'keyword-group:'.$second->id, 'score' => 0.7, 'evidence' => ['lexical_matched' => false]],
                ],
            ]),
        ]);

        $result = (new TopicGroupArticleRetriever(new TopicGroupRetrievalClient(new SemanticAnalyticsClient())))
            ->retrieve(6, 'máy phát điện');

        $sent = Http::recorded()[0][0]->data()['groups'];
        $refs = array_column($sent, 'ref');
        self::assertSame([
            'keyword-group:'.$first->id,
            'keyword-group:'.$second->id,
        ], $refs);
        self::assertSame('ok', $result['status']);
        self::assertCount(2, $result['groups']);
    }

    public function test_duplicate_refs_match_against_the_live_semantic_service(): void
    {
        config(['semantic.url' => 'http://127.0.0.1:8088', 'semantic.timeout' => 30]);
        $first = SeoKeywordGroup::query()->create([
            'site_id' => 6,
            'name' => 'Máy phát điện',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'g-0004',
            'is_locked' => false,
        ]);
        $second = SeoKeywordGroup::query()->create([
            'site_id' => 6,
            'name' => 'Máy phát điện cũ',
            'source' => KeywordGroupSource::SEMANTIC,
            'semantic_group_ref' => 'g-0004',
            'is_locked' => false,
        ]);
        foreach ([[$first, 'máy phát điện'], [$second, 'máy phát điện cũ']] as [$group, $phrase]) {
            $keyword = Keyword::query()->create([
                'phrase' => $phrase,
                'type' => Keyword::TYPE_NORMAL,
                'review_status' => 'active',
            ]);
            SeoKeywordGroupKeyword::query()->create([
                'site_id' => 6,
                'group_id' => $group->id,
                'keyword_id' => $keyword->id,
                'source' => KeywordGroupSource::SEMANTIC,
            ]);
        }

        $seen = null;
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$seen): void {
            if (str_contains($event->request->url(), '/v1/topic-groups/matches')) {
                $seen = $event;
            }
        });

        $result = (new TopicGroupArticleRetriever(new TopicGroupRetrievalClient(new SemanticAnalyticsClient())))
            ->retrieve(6, 'máy phát điện công nghiệp');

        self::assertInstanceOf(ResponseReceived::class, $seen);
        $refs = array_column($seen->request->data()['groups'], 'ref');
        self::assertCount(count(array_unique($refs)), $refs);
        self::assertContains('keyword-group:'.$first->id, $refs);
        self::assertContains('keyword-group:'.$second->id, $refs);
        self::assertSame(200, $seen->response->status());
        self::assertSame('ok', $result['status']);
    }
}
