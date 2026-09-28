<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\SiteNetworkReadModel;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Tests\TestCase;

final class SiteNetworkReadModelTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootSchemas();

        $this->owner = User::query()->forceCreate([
            'id' => 10,
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
        ]);

        Auth::login($this->owner);
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

        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('domain');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('articles');
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->string('status')->default('published');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->dropIfExists('wordpress_article_links');
        Schema::connection('omi_seo_ai')->create('wordpress_article_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable();
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

    public function test_a_resolved_cross_site_link(): void
    {
        Site::query()->forceCreate(['id' => 1, 'user_id' => $this->owner->id, 'domain' => 'site-a.com']);
        Site::query()->forceCreate(['id' => 2, 'user_id' => $this->owner->id, 'domain' => 'site-b.com']);

        $sourceArticle = SeoArticle::query()->create(['id' => 101, 'site_id' => 1, 'title' => 'Article A']);
        $targetArticle = SeoArticle::query()->create(['id' => 201, 'site_id' => 2, 'title' => 'Article B']);

        SeoLinkMap::query()->create([
            'keyword_id' => 501,
            'source_article_id' => (int) $sourceArticle->id,
            'target_article_id' => (int) $targetArticle->id,
            'target_site_id' => 2,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        $model = new SiteNetworkReadModel();
        $overview = $model->overview();

        $this->assertCount(2, $overview['sites']);
        $this->assertCount(1, $overview['edges']);

        $edge = $overview['edges'][0];
        $this->assertSame('site:1', $edge['source_site_ref']);
        $this->assertSame('site:2', $edge['target_site_ref']);
        $this->assertSame(1, $edge['article_link_count']);
        $this->assertSame(1, $edge['source_article_count']);
        $this->assertSame(1, $edge['target_article_count']);
        $this->assertSame(1, $edge['source_keyword_count']);
    }

    public function test_b_unresolved_target_article(): void
    {
        Site::query()->forceCreate(['id' => 1, 'user_id' => $this->owner->id, 'domain' => 'site-a.com']);
        Site::query()->forceCreate(['id' => 2, 'user_id' => $this->owner->id, 'domain' => 'site-b.com']);

        $sourceArticle = SeoArticle::query()->create(['id' => 101, 'site_id' => 1, 'title' => 'Article A']);

        SeoLinkMap::query()->create([
            'keyword_id' => 501,
            'source_article_id' => (int) $sourceArticle->id,
            'target_article_id' => null,
            'target_site_id' => 2,
            'target_external_url' => 'https://site-b.com/uncrawled-page',
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        $model = new SiteNetworkReadModel();
        $overview = $model->overview();

        $this->assertCount(2, $overview['sites']);
        $this->assertCount(1, $overview['edges']);

        $edge = $overview['edges'][0];
        $this->assertSame('site:1', $edge['source_site_ref']);
        $this->assertSame('site:2', $edge['target_site_ref']);
        $this->assertSame(1, $edge['article_link_count']);
        $this->assertSame(1, $edge['source_article_count']);
        $this->assertSame(0, $edge['target_article_count']);
        $this->assertSame(1, $edge['source_keyword_count']);
    }

    public function test_c_mix_resolved_and_unresolved(): void
    {
        Site::query()->forceCreate(['id' => 1, 'user_id' => $this->owner->id, 'domain' => 'site-a.com']);
        Site::query()->forceCreate(['id' => 2, 'user_id' => $this->owner->id, 'domain' => 'site-b.com']);

        $sourceArticle1 = SeoArticle::query()->create(['id' => 101, 'site_id' => 1, 'title' => 'Article A1']);
        $sourceArticle2 = SeoArticle::query()->create(['id' => 102, 'site_id' => 1, 'title' => 'Article A2']);
        $targetArticle1 = SeoArticle::query()->create(['id' => 201, 'site_id' => 2, 'title' => 'Article B1']);

        // 2 resolved links pointing to target article B1
        SeoLinkMap::query()->create([
            'keyword_id' => 501,
            'source_article_id' => (int) $sourceArticle1->id,
            'target_article_id' => (int) $targetArticle1->id,
            'target_site_id' => 2,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);
        SeoLinkMap::query()->create([
            'keyword_id' => 502,
            'source_article_id' => (int) $sourceArticle2->id,
            'target_article_id' => (int) $targetArticle1->id,
            'target_site_id' => 2,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        // 1 unresolved link pointing to site B
        SeoLinkMap::query()->create([
            'keyword_id' => 503,
            'source_article_id' => (int) $sourceArticle1->id,
            'target_article_id' => null,
            'target_site_id' => 2,
            'target_external_url' => 'https://site-b.com/uncrawled',
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        $model = new SiteNetworkReadModel();
        $overview = $model->overview();

        $this->assertCount(1, $overview['edges']);
        $edge = $overview['edges'][0];
        $this->assertSame(3, $edge['article_link_count']);
        $this->assertSame(2, $edge['source_article_count']);
        $this->assertSame(1, $edge['target_article_count'], 'Must count distinct resolved target articles only');
        $this->assertSame(3, $edge['source_keyword_count']);
    }

    public function test_d_directionality_edges_are_independent(): void
    {
        Site::query()->forceCreate(['id' => 1, 'user_id' => $this->owner->id, 'domain' => 'site-a.com']);
        Site::query()->forceCreate(['id' => 2, 'user_id' => $this->owner->id, 'domain' => 'site-b.com']);

        $artA = SeoArticle::query()->create(['id' => 101, 'site_id' => 1, 'title' => 'Article A']);
        $artB = SeoArticle::query()->create(['id' => 201, 'site_id' => 2, 'title' => 'Article B']);

        // A -> B
        SeoLinkMap::query()->create([
            'keyword_id' => 501,
            'source_article_id' => (int) $artA->id,
            'target_article_id' => (int) $artB->id,
            'target_site_id' => 2,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        // B -> A
        SeoLinkMap::query()->create([
            'keyword_id' => 502,
            'source_article_id' => (int) $artB->id,
            'target_article_id' => null,
            'target_site_id' => 1,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        $model = new SiteNetworkReadModel();
        $overview = $model->overview();

        $this->assertCount(2, $overview['edges']);

        $edgeAtoB = collect($overview['edges'])->firstWhere('source_site_ref', 'site:1');
        $edgeBtoA = collect($overview['edges'])->firstWhere('source_site_ref', 'site:2');

        $this->assertNotNull($edgeAtoB);
        $this->assertNotNull($edgeBtoA);
        $this->assertSame('site:2', $edgeAtoB['target_site_ref']);
        $this->assertSame(1, $edgeAtoB['target_article_count']);

        $this->assertSame('site:1', $edgeBtoA['target_site_ref']);
        $this->assertSame(0, $edgeBtoA['target_article_count']);
    }

    public function test_e_topic_drilldown_with_unresolved_target_article(): void
    {
        Site::query()->forceCreate(['id' => 1, 'user_id' => $this->owner->id, 'domain' => 'site-a.com']);
        Site::query()->forceCreate(['id' => 2, 'user_id' => $this->owner->id, 'domain' => 'site-b.com']);

        $sourceArticle = SeoArticle::query()->create(['id' => 101, 'site_id' => 1, 'title' => 'Article A']);

        DB::connection('omi_seo_ai')->table('seo_topics')->insert([
            'id' => 88,
            'site_id' => 1,
            'name' => 'Topic Coffee',
        ]);
        DB::connection('omi_seo_ai')->table('seo_topic_keywords')->insert([
            'site_id' => 1,
            'topic_id' => 88,
            'keyword_id' => 501,
        ]);

        // Unresolved target article on Site B
        SeoLinkMap::query()->create([
            'keyword_id' => 501,
            'source_article_id' => (int) $sourceArticle->id,
            'target_article_id' => null,
            'target_site_id' => 2,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        $model = new SiteNetworkReadModel();
        $drilldown = $model->topicsForSitePair(1, 2);

        $this->assertNotNull($drilldown);
        $this->assertSame('site:1', $drilldown['source_site_ref']);
        $this->assertSame('site:2', $drilldown['target_site_ref']);
        $this->assertCount(1, $drilldown['topics']);
        $this->assertSame(88, $drilldown['topics'][0]['topic_id']);
        $this->assertSame('Topic Coffee', $drilldown['topics'][0]['name']);
        $this->assertSame(1, $drilldown['topics'][0]['cross_site_link_count']);
    }

    public function test_f_tenant_scope_filters_out_unaccessible_sites(): void
    {
        $otherUser = User::query()->forceCreate([
            'id' => 99,
            'name' => 'Other Owner',
            'email' => 'other@example.com',
            'role' => User::ROLE_OWNER,
        ]);

        // Owner 10 owns Site 1 and Site 2
        Site::query()->forceCreate(['id' => 1, 'user_id' => $this->owner->id, 'domain' => 'site-a.com']);
        Site::query()->forceCreate(['id' => 2, 'user_id' => $this->owner->id, 'domain' => 'site-b.com']);

        // Other owner 99 owns Site 3
        Site::query()->forceCreate(['id' => 3, 'user_id' => $otherUser->id, 'domain' => 'site-c.com']);

        $artA = SeoArticle::query()->create(['id' => 101, 'site_id' => 1, 'title' => 'Article A']);
        $artB = SeoArticle::query()->create(['id' => 201, 'site_id' => 2, 'title' => 'Article B']);
        $artC = SeoArticle::query()->create(['id' => 301, 'site_id' => 3, 'title' => 'Article C']);

        // A -> B (both accessible)
        SeoLinkMap::query()->create([
            'keyword_id' => 501,
            'source_article_id' => (int) $artA->id,
            'target_article_id' => (int) $artB->id,
            'target_site_id' => 2,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        // A -> C (C is inaccessible to owner 10)
        SeoLinkMap::query()->create([
            'keyword_id' => 502,
            'source_article_id' => (int) $artA->id,
            'target_article_id' => (int) $artC->id,
            'target_site_id' => 3,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        $model = new SiteNetworkReadModel();
        $overview = $model->overview();

        $siteIds = array_column($overview['sites'], 'site_id');
        $this->assertContains(1, $siteIds);
        $this->assertContains(2, $siteIds);
        $this->assertNotContains(3, $siteIds, 'Inaccessible Site C must not appear in sites');

        $this->assertCount(1, $overview['edges']);
        $this->assertSame('site:1', $overview['edges'][0]['source_site_ref']);
        $this->assertSame('site:2', $overview['edges'][0]['target_site_ref']);
    }

    public function test_g_zero_accessible_sites_fails_closed(): void
    {
        // Another owner owns Site 1 and Site 2
        Site::query()->forceCreate(['id' => 1, 'user_id' => 999, 'domain' => 'site-a.com']);
        Site::query()->forceCreate(['id' => 2, 'user_id' => 999, 'domain' => 'site-b.com']);

        $artA = SeoArticle::query()->create(['id' => 101, 'site_id' => 1, 'title' => 'Article A']);
        $artB = SeoArticle::query()->create(['id' => 201, 'site_id' => 2, 'title' => 'Article B']);

        SeoLinkMap::query()->create([
            'keyword_id' => 501,
            'source_article_id' => (int) $artA->id,
            'target_article_id' => (int) $artB->id,
            'target_site_id' => 2,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        // Current logged-in user ($this->owner with id 10) owns 0 sites
        $model = new SiteNetworkReadModel();
        $overview = $model->overview();

        $this->assertSame([], $overview['sites'], 'Must fail closed: sites must be empty');
        $this->assertSame([], $overview['edges'], 'Must fail closed: edges must be empty');

        $drilldown = $model->topicsForSitePair(1, 2);
        $this->assertNull($drilldown, 'Drilldown must return null when sites are inaccessible');
    }

    public function test_h_legacy_row_derives_target_site_from_target_article(): void
    {
        Site::query()->forceCreate(['id' => 1, 'user_id' => $this->owner->id, 'domain' => 'site-a.com']);
        Site::query()->forceCreate(['id' => 2, 'user_id' => $this->owner->id, 'domain' => 'site-b.com']);

        $artA = SeoArticle::query()->create(['id' => 101, 'site_id' => 1, 'title' => 'Article A']);
        $artB = SeoArticle::query()->create(['id' => 201, 'site_id' => 2, 'title' => 'Article B']);

        // Legacy row has link_type='external', target_site_id=null, but target_article_id=201
        SeoLinkMap::query()->create([
            'keyword_id' => 501,
            'source_article_id' => (int) $artA->id,
            'target_article_id' => (int) $artB->id,
            'target_site_id' => null,
            'link_type' => SeoLinkMapType::External->value,
            'status' => SeoLinkMapStatus::Active,
        ]);

        $model = new SiteNetworkReadModel();
        $overview = $model->overview();

        $this->assertCount(1, $overview['edges']);
        $edge = $overview['edges'][0];
        $this->assertSame('site:1', $edge['source_site_ref']);
        $this->assertSame('site:2', $edge['target_site_ref']);
        $this->assertSame(1, $edge['article_link_count']);
        $this->assertSame(1, $edge['target_article_count']);
    }
}

