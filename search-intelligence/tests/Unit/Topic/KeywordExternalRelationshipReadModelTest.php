<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordExternalRelationshipReadModel;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Tests\TestCase;

final class KeywordExternalRelationshipReadModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSchemas();

        $owner = User::query()->forceCreate([
            'id' => 10,
            'name' => 'Owner',
            'email' => 'owner-external@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
        ]);
        Auth::login($owner);
    }

    public function test_external_projection_keeps_semantic_categories_and_drops_cta(): void
    {
        $source = SeoArticle::query()->create(['id' => 101, 'site_id' => 1, 'title' => 'Article A']);
        $target = SeoArticle::query()->create(['id' => 201, 'site_id' => 2, 'title' => 'Article B']);
        DB::connection('omi_seo_ai')->table('keywords')->insert([
            'id' => 501,
            'phrase' => 'keyword a',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('omi_seo_ai')->table('seo_topics')->insert([
            'id' => 11,
            'site_id' => 1,
            'name' => 'Topic Alpha',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('omi_seo_ai')->table('seo_topic_keywords')->insert([
            'site_id' => 1,
            'topic_id' => 11,
            'keyword_id' => 501,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->map($source, 501, SeoLinkMapType::ManagedCrossSite, [
            'target_article_id' => (int) $target->id,
            'target_site_id' => 2,
        ]);
        $this->map($source, 501, SeoLinkMapType::ManagedCrossSite, [
            'target_article_id' => null,
            'target_site_id' => 2,
            'target_external_url' => 'https://site-b.test/unresolved',
        ]);
        $this->map($source, 501, SeoLinkMapType::WikiTrust, [
            'target_external_url' => 'https://en.wikipedia.org/wiki/Example',
        ]);
        $this->map($source, 501, SeoLinkMapType::External, [
            'target_external_url' => 'https://unknown.example/page',
        ]);
        $this->map($source, 501, SeoLinkMapType::NeedsReview, [
            'target_external_url' => 'https://review.example/page',
        ]);
        $this->map($source, 501, SeoLinkMapType::Social, [
            'target_external_url' => 'https://facebook.com/page',
        ]);
        $this->map($source, 501, SeoLinkMapType::Contact, [
            'target_external_url' => 'mailto:hello@example.com',
        ]);
        $this->map($source, 501, SeoLinkMapType::Internal, [
            'target_article_id' => (int) $source->id,
            'target_site_id' => 1,
        ]);

        $model = new KeywordExternalRelationshipReadModel();
        $page = $model->externalLinks(1, null, 1, 50);
        $items = $page['items'];

        self::assertTrue($page['available']);
        self::assertCount(5, $items);

        $byType = [];
        foreach ($items as $item) {
            $byType[$item['link_type']][] = $item;
        }

        self::assertArrayNotHasKey(SeoLinkMapType::Social->value, $byType);
        self::assertArrayNotHasKey(SeoLinkMapType::Contact->value, $byType);
        self::assertArrayNotHasKey(SeoLinkMapType::Internal->value, $byType);

        $managed = $byType[SeoLinkMapType::ManagedCrossSite->value];
        self::assertCount(2, $managed);
        foreach ($managed as $item) {
            self::assertSame('managed_cross_site', $item['ui_category']);
            self::assertSame(2, $item['target_site_id']);
            self::assertSame('Topic Alpha', $item['source_topic_name']);
            self::assertSame('keyword a', $item['source_keyword_phrase']);
        }

        $unresolved = null;
        foreach ($managed as $item) {
            if ($item['target_article_id'] === null) {
                $unresolved = $item;
            }
        }
        self::assertNotNull($unresolved);
        self::assertSame('managed_cross_site', $unresolved['ui_category']);
        self::assertNull($unresolved['target_keyword_id']);
        self::assertNull($unresolved['target_keyword_phrase']);
        self::assertFalse($unresolved['target_keyword_resolved']);

        self::assertSame('reference', $byType[SeoLinkMapType::WikiTrust->value][0]['ui_category']);
        self::assertNull($byType[SeoLinkMapType::WikiTrust->value][0]['target_keyword_id']);
        self::assertSame('needs_review', $byType[SeoLinkMapType::External->value][0]['ui_category']);
        self::assertNull($byType[SeoLinkMapType::External->value][0]['target_keyword_phrase']);
        self::assertSame('needs_review', $byType[SeoLinkMapType::NeedsReview->value][0]['ui_category']);

        $counts = $model->categoryCounts(1);
        self::assertSame(5, $counts['all']);
        self::assertSame(2, $counts['managed_cross_site']);
        self::assertSame(1, $counts['reference']);
        self::assertSame(2, $counts['needs_review']);

        $topicRows = $model->forTopic(1, 11, 20);
        self::assertCount(5, $topicRows['items']);
        foreach ($topicRows['items'] as $item) {
            self::assertSame(501, $item['source_keyword_id']);
            self::assertNull($item['target_keyword_id']);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function map(SeoArticle $source, int $keywordId, SeoLinkMapType $type, array $extra = []): void
    {
        SeoLinkMap::query()->create(array_merge([
            'keyword_id' => $keywordId,
            'source_article_id' => (int) $source->id,
            'anchor_text' => 'anchor',
            'link_type' => $type->value,
            'status' => SeoLinkMapStatus::Active,
            'is_semantic_eligible' => true,
        ], $extra));
    }

    private function bootSchemas(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->default('owner');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('wordpress_article_links');
        Schema::connection('omi_seo_ai')->create('wordpress_article_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('articles');
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('keywords');
        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('keyword_meta');
        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('seo_link_maps');
        Schema::connection('omi_seo_ai')->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->nullable();
            $table->unsignedBigInteger('source_article_id');
            $table->unsignedBigInteger('target_article_id')->nullable();
            $table->unsignedBigInteger('target_site_id')->nullable();
            $table->text('target_external_url')->nullable();
            $table->string('anchor_text')->nullable();
            $table->string('link_type')->default('internal');
            $table->string('destination_kind')->nullable();
            $table->boolean('is_semantic_eligible')->default(true);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('seo_topics');
        Schema::connection('omi_seo_ai')->create('seo_topics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('seo_topic_keywords');
        Schema::connection('omi_seo_ai')->create('seo_topic_keywords', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('keyword_id');
            $table->timestamps();
        });
    }
}
