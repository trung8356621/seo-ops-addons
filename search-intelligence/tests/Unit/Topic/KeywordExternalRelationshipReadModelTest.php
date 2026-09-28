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

    public function test_risk_level_derivation_and_mapping(): void
    {
        $model = new KeywordExternalRelationshipReadModel();

        // 1. Stable internal keys
        self::assertSame(['all', 'safe', 'low', 'review'], KeywordExternalRelationshipReadModel::uiRiskFilters());
        self::assertSame(['safe', 'low', 'review'], KeywordExternalRelationshipReadModel::riskLevels());

        // 2. Link type to risk level mappings
        self::assertSame('safe', $model->riskLevelForType(SeoLinkMapType::ManagedCrossSite));
        self::assertSame('safe', $model->riskLevelForType(SeoLinkMapType::ManagedCrossSite->value));

        self::assertSame('low', $model->riskLevelForType(SeoLinkMapType::WikiTrust));
        self::assertSame('low', $model->riskLevelForType(SeoLinkMapType::WikiTrust->value));

        self::assertSame('review', $model->riskLevelForType(SeoLinkMapType::NeedsReview));
        self::assertSame('review', $model->riskLevelForType(SeoLinkMapType::NeedsReview->value));

        self::assertSame('review', $model->riskLevelForType(SeoLinkMapType::External));
        self::assertSame('review', $model->riskLevelForType(SeoLinkMapType::External->value));

        // Fallbacks for any unclassified or unknown link type
        self::assertSame('review', $model->riskLevelForType('unknown_type'));

        // 3. Types for risk level
        self::assertSame(['managed_cross_site'], $model->typesForRiskLevel('safe'));
        self::assertSame(['wiki_trust'], $model->typesForRiskLevel('low'));
        self::assertSame(['needs_review', 'external'], $model->typesForRiskLevel('review'));
        self::assertNull($model->typesForRiskLevel('all'));
        self::assertNull($model->typesForRiskLevel('unknown'));
    }

    public function test_risk_badge_and_reason_fields_in_presented_items(): void
    {
        $source = SeoArticle::query()->create(['id' => 102, 'site_id' => 1, 'title' => 'Article Source']);
        $target = SeoArticle::query()->create(['id' => 202, 'site_id' => 2, 'title' => 'Article Target']);

        $this->map($source, 0, SeoLinkMapType::ManagedCrossSite, [
            'target_article_id' => (int) $target->id,
            'target_site_id' => 2,
        ]);
        $this->map($source, 0, SeoLinkMapType::WikiTrust, [
            'target_external_url' => 'https://en.wikipedia.org/wiki/Test',
        ]);
        $this->map($source, 0, SeoLinkMapType::NeedsReview, [
            'target_external_url' => 'https://unvetted.example/test',
        ]);
        $this->map($source, 0, SeoLinkMapType::External, [
            'target_external_url' => 'https://legacy.example/test',
        ]);

        $model = new KeywordExternalRelationshipReadModel();
        $page = $model->externalLinks(1, null, 1, 10);
        $items = collect($page['items'])->keyBy('link_type');

        // Managed cross-site -> safe
        $managed = $items->get(SeoLinkMapType::ManagedCrossSite->value);
        self::assertNotNull($managed);
        self::assertSame('safe', $managed['risk_level']);
        self::assertNotEmpty($managed['risk_level_label']);
        self::assertNotEmpty($managed['risk_reason']);

        // WikiTrust -> low
        $wiki = $items->get(SeoLinkMapType::WikiTrust->value);
        self::assertNotNull($wiki);
        self::assertSame('low', $wiki['risk_level']);
        self::assertNotEmpty($wiki['risk_level_label']);
        self::assertNotEmpty($wiki['risk_reason']);

        // NeedsReview -> review
        $review = $items->get(SeoLinkMapType::NeedsReview->value);
        self::assertNotNull($review);
        self::assertSame('review', $review['risk_level']);
        self::assertNotEmpty($review['risk_level_label']);
        self::assertNotEmpty($review['risk_reason']);

        // Legacy External -> review
        $external = $items->get(SeoLinkMapType::External->value);
        self::assertNotNull($external);
        self::assertSame('review', $external['risk_level']);
        self::assertNotEmpty($external['risk_level_label']);
        self::assertNotEmpty($external['risk_reason']);
    }

    public function test_risk_counts_and_risk_level_filtering(): void
    {
        $source = SeoArticle::query()->create(['id' => 103, 'site_id' => 1, 'title' => 'Article Source 3']);
        $target = SeoArticle::query()->create(['id' => 203, 'site_id' => 2, 'title' => 'Article Target 3']);

        // 2 Safe
        $this->map($source, 0, SeoLinkMapType::ManagedCrossSite, ['target_article_id' => (int) $target->id, 'target_site_id' => 2]);
        $this->map($source, 0, SeoLinkMapType::ManagedCrossSite, ['target_external_url' => 'https://site-2.test/p']);

        // 1 Low
        $this->map($source, 0, SeoLinkMapType::WikiTrust, ['target_external_url' => 'https://en.wikipedia.org/wiki/Page']);

        // 2 Review (1 NeedsReview, 1 External legacy)
        $this->map($source, 0, SeoLinkMapType::NeedsReview, ['target_external_url' => 'https://review.com']);
        $this->map($source, 0, SeoLinkMapType::External, ['target_external_url' => 'https://legacy.com']);

        // Social & Contact (MUST be excluded entirely)
        $this->map($source, 0, SeoLinkMapType::Social, ['target_external_url' => 'https://twitter.com']);
        $this->map($source, 0, SeoLinkMapType::Contact, ['target_external_url' => 'mailto:info@example.com']);

        $model = new KeywordExternalRelationshipReadModel();

        // Check risk counts
        $counts = $model->riskCounts(1);
        self::assertTrue($counts['available']);
        self::assertSame(5, $counts['all']);
        self::assertSame(2, $counts['safe']);
        self::assertSame(1, $counts['low']);
        self::assertSame(2, $counts['review']);

        // Check querying by 'safe'
        $safePage = $model->externalLinksForRiskLevel(1, 'safe', 1, 10);
        self::assertSame(2, $safePage['total']);
        self::assertCount(2, $safePage['items']);
        foreach ($safePage['items'] as $item) {
            self::assertSame('safe', $item['risk_level']);
            self::assertSame(SeoLinkMapType::ManagedCrossSite->value, $item['link_type']);
        }

        // Check querying by 'low'
        $lowPage = $model->externalLinksForRiskLevel(1, 'low', 1, 10);
        self::assertSame(1, $lowPage['total']);
        self::assertCount(1, $lowPage['items']);
        self::assertSame('low', $lowPage['items'][0]['risk_level']);
        self::assertSame(SeoLinkMapType::WikiTrust->value, $lowPage['items'][0]['link_type']);

        // Check querying by 'review' (includes both NeedsReview and External)
        $reviewPage = $model->externalLinksForRiskLevel(1, 'review', 1, 10);
        self::assertSame(2, $reviewPage['total']);
        self::assertCount(2, $reviewPage['items']);
        foreach ($reviewPage['items'] as $item) {
            self::assertSame('review', $item['risk_level']);
            self::assertContains($item['link_type'], [SeoLinkMapType::NeedsReview->value, SeoLinkMapType::External->value]);
        }

        // Check querying by 'all'
        $allPage = $model->externalLinksForRiskLevel(1, 'all', 1, 10);
        self::assertSame(5, $allPage['total']);
        self::assertCount(5, $allPage['items']);

        // Verify Social and Contact are never present in any risk level query
        $allTypes = array_column($allPage['items'], 'link_type');
        self::assertNotContains(SeoLinkMapType::Social->value, $allTypes);
        self::assertNotContains(SeoLinkMapType::Contact->value, $allTypes);
    }

    public function test_risk_workspace_pagination(): void
    {
        $source = SeoArticle::query()->create(['id' => 104, 'site_id' => 1, 'title' => 'Article Source 4']);

        $this->map($source, 0, SeoLinkMapType::NeedsReview, ['target_external_url' => 'https://item1.test']);
        $this->map($source, 0, SeoLinkMapType::NeedsReview, ['target_external_url' => 'https://item2.test']);
        $this->map($source, 0, SeoLinkMapType::NeedsReview, ['target_external_url' => 'https://item3.test']);

        $model = new KeywordExternalRelationshipReadModel();

        // Page 1 with perPage 2
        $p1 = $model->externalLinksForRiskLevel(1, 'review', 1, 2);
        self::assertSame(3, $p1['total']);
        self::assertSame(1, $p1['page']);
        self::assertSame(2, $p1['per_page']);
        self::assertCount(2, $p1['items']);

        // Page 2 with perPage 2
        $p2 = $model->externalLinksForRiskLevel(1, 'review', 2, 2);
        self::assertSame(3, $p2['total']);
        self::assertSame(2, $p2['page']);
        self::assertSame(2, $p2['per_page']);
        self::assertCount(1, $p2['items']);
    }

    public function test_no_fake_target_keyword_in_risk_relationships(): void
    {
        $source = SeoArticle::query()->create(['id' => 105, 'site_id' => 1, 'title' => 'Article Source 5']);

        // Unresolved target article
        $this->map($source, 0, SeoLinkMapType::ManagedCrossSite, [
            'target_article_id' => null,
            'target_site_id' => 2,
            'target_external_url' => 'https://site2.test/unresolved',
        ]);

        $model = new KeywordExternalRelationshipReadModel();
        $page = $model->externalLinksForRiskLevel(1, 'safe', 1, 10);
        self::assertCount(1, $page['items']);

        $item = $page['items'][0];
        self::assertNull($item['target_keyword_id'], 'Must not have fake target keyword ID');
        self::assertNull($item['target_keyword_phrase'], 'Must not have fake target keyword phrase');
        self::assertFalse($item['target_keyword_resolved'], 'Must explicitly mark target keyword as unresolved');
    }

    public function test_keyword_risk_workspace_component_defaults(): void
    {
        $workspace = new \Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordRiskWorkspace();

        // Default filter must be 'review'
        self::assertSame('review', $workspace->riskFilter);

        // Setting valid filter updates state
        $workspace->setRiskFilter('safe');
        self::assertSame('safe', $workspace->riskFilter);

        $workspace->setRiskFilter('low');
        self::assertSame('low', $workspace->riskFilter);

        $workspace->setRiskFilter('all');
        self::assertSame('all', $workspace->riskFilter);

        // Setting invalid filter is ignored
        $workspace->setRiskFilter('unsupported_value');
        self::assertSame('all', $workspace->riskFilter, 'Invalid filter must not change riskFilter');
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
