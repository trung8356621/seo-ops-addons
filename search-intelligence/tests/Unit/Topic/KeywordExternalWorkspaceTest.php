<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordExternalWorkspace;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use ReflectionMethod;
use Tests\TestCase;

final class KeywordExternalWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSchemas();

        $owner = User::query()->forceCreate([
            'id' => 10,
            'name' => 'Owner',
            'email' => 'owner-workspace@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
        ]);
        Auth::login($owner);

        DB::table('sites')->insert([
            ['id' => 1, 'user_id' => 10, 'name' => 'Site A', 'domain' => 'site-a.test', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'user_id' => 10, 'name' => 'Site B', 'domain' => 'site-b.test', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_workspace_mount_with_default_filters(): void
    {
        $workspace = new KeywordExternalWorkspace();
        $workspace->mount();

        self::assertSame('all', $workspace->filter);
        self::assertSame('', $workspace->risk);
    }

    public function test_workspace_mount_maps_legacy_query_parameters(): void
    {
        $request = Request::create('/keywords/external', 'GET', ['risk' => 'review']);
        $this->app->instance('request', $request);

        $workspace = new KeywordExternalWorkspace();
        $workspace->mount();

        self::assertSame('needs_review', $workspace->filter);
        self::assertSame('', $workspace->risk);

        $request = Request::create('/keywords/external', 'GET', ['risk' => 'safe']);
        $this->app->instance('request', $request);

        $workspace = new KeywordExternalWorkspace();
        $workspace->mount();

        self::assertSame('managed_cross_site', $workspace->filter);

        $request = Request::create('/keywords/external', 'GET', ['risk' => 'low']);
        $this->app->instance('request', $request);

        $workspace = new KeywordExternalWorkspace();
        $workspace->mount();

        self::assertSame('reference', $workspace->filter);

        $request = Request::create('/keywords/external', 'GET', ['external' => 'reference']);
        $this->app->instance('request', $request);

        $workspace = new KeywordExternalWorkspace();
        $workspace->mount();

        self::assertSame('reference', $workspace->filter);
    }

    public function test_augment_items_populates_draft_presence_and_metadata(): void
    {
        $source1 = SeoArticle::query()->create(['id' => 301, 'site_id' => 1, 'title' => 'Article One']);
        $source2 = SeoArticle::query()->create(['id' => 302, 'site_id' => 1, 'title' => 'Article Two']);

        // Article 301 is already in Draft planning pool
        SeoProjectTask::query()->create([
            'id' => 501,
            'project_id' => 1,
            'article_id' => (int) $source1->id,
            'site_id' => 1,
            'type' => SeoProjectTask::TYPE_IMPROVE,
            'status' => 'draft',
        ]);

        $items = [
            [
                'map_id' => 101,
                'source_article_id' => 301,
                'source_article_title' => 'Article One',
                'source_site_id' => 1,
                'target_site_id' => 2,
            ],
            [
                'map_id' => 102,
                'source_article_id' => 302,
                'source_article_title' => 'Article Two',
                'source_site_id' => 1,
                'target_site_id' => 0,
            ],
        ];

        $workspace = new KeywordExternalWorkspace();
        $method = new ReflectionMethod($workspace, 'augmentItems');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $augmented */
        $augmented = $method->invoke($workspace, $items);

        self::assertCount(2, $augmented);
        self::assertTrue($augmented[0]['is_source_in_draft'], 'Article 301 must be flagged as in draft');
        self::assertFalse($augmented[1]['is_source_in_draft'], 'Article 302 must NOT be flagged as in draft');
        self::assertArrayHasKey('source_article_edit_url', $augmented[0]);
        self::assertArrayHasKey('source_article_edit_url', $augmented[1]);
    }

    public function test_push_to_draft_delegates_to_intake_service_with_improve_type_and_notes(): void
    {
        $source = SeoArticle::query()->create(['id' => 401, 'site_id' => 1, 'title' => 'Source Article 401']);

        $map = SeoLinkMap::query()->create([
            'id' => 701,
            'keyword_id' => null,
            'source_article_id' => (int) $source->id,
            'anchor_text' => 'sample link',
            'link_type' => SeoLinkMapType::NeedsReview->value,
            'target_external_url' => 'https://unmanaged.example.org/suspicious',
            'status' => SeoLinkMapStatus::Active->value,
            'is_semantic_eligible' => true,
        ]);

        $mockIntake = $this->createMock(PlanningDraftIntakeService::class);
        $mockIntake->expects(self::once())
            ->method('addArticles')
            ->with(
                self::callback(function ($articles) use ($source): bool {
                    $arr = is_array($articles) ? $articles : iterator_to_array($articles);

                    return count($arr) === 1 && (int) $arr[0]->id === (int) $source->id;
                }),
                self::isNull(),
                self::identicalTo(SeoProjectTask::TYPE_IMPROVE),
                self::isNull(),
                self::callback(function (?string $notes) use ($map): bool {
                    return $notes !== null
                        && str_contains($notes, 'https://unmanaged.example.org/suspicious')
                        && str_contains($notes, 'Warning / unmanaged')
                        && str_contains($notes, (string) $map->id);
                }),
            )
            ->willReturn(new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_ADDED,
                1,
                message: 'Added to Draft',
                articleIds: [(int) $source->id],
            ));

        $this->app->instance(PlanningDraftIntakeService::class, $mockIntake);

        $workspace = new KeywordExternalWorkspace();
        $workspace->pushToDraft((int) $map->id);
    }

    public function test_push_to_draft_idempotency_returns_already_in_draft(): void
    {
        $source = SeoArticle::query()->create(['id' => 402, 'site_id' => 1, 'title' => 'Source Article 402']);

        $map = SeoLinkMap::query()->create([
            'id' => 702,
            'keyword_id' => null,
            'source_article_id' => (int) $source->id,
            'anchor_text' => 'another link',
            'link_type' => SeoLinkMapType::External->value,
            'target_external_url' => 'https://external.example.org/resource',
            'status' => SeoLinkMapStatus::Active->value,
            'is_semantic_eligible' => true,
        ]);

        $mockIntake = $this->createMock(PlanningDraftIntakeService::class);
        $mockIntake->expects(self::once())
            ->method('addArticles')
            ->willReturn(new PlanningDraftIntakeResult(
                PlanningDraftIntakeResult::STATUS_ALREADY_IN_DRAFT,
                1,
                message: 'Already in Draft',
                articleIds: [(int) $source->id],
            ));

        $this->app->instance(PlanningDraftIntakeService::class, $mockIntake);

        $workspace = new KeywordExternalWorkspace();
        $workspace->pushToDraft((int) $map->id);
    }

    public function test_push_to_draft_blocked_for_non_warning_relationships(): void
    {
        $source = SeoArticle::query()->create(['id' => 403, 'site_id' => 1, 'title' => 'Source Article 403']);

        // Safe relationship (Managed Cross-Site)
        $mapSafe = SeoLinkMap::query()->create([
            'id' => 703,
            'keyword_id' => null,
            'source_article_id' => (int) $source->id,
            'target_site_id' => 2,
            'link_type' => SeoLinkMapType::ManagedCrossSite->value,
            'status' => SeoLinkMapStatus::Active->value,
            'is_semantic_eligible' => true,
        ]);

        // Low risk relationship (WikiTrust)
        $mapLow = SeoLinkMap::query()->create([
            'id' => 704,
            'keyword_id' => null,
            'source_article_id' => (int) $source->id,
            'link_type' => SeoLinkMapType::WikiTrust->value,
            'target_external_url' => 'https://en.wikipedia.org/wiki/Test',
            'status' => SeoLinkMapStatus::Active->value,
            'is_semantic_eligible' => true,
        ]);

        $mockIntake = $this->createMock(PlanningDraftIntakeService::class);
        $mockIntake->expects(self::never())->method('addArticles');
        $this->app->instance(PlanningDraftIntakeService::class, $mockIntake);

        $workspace = new KeywordExternalWorkspace();
        $workspace->pushToDraft((int) $mapSafe->id);
        $workspace->pushToDraft((int) $mapLow->id);
    }

    public function test_push_to_draft_forbidden_for_unauthorized_users(): void
    {
        $viewer = User::query()->forceCreate([
            'id' => 20,
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => bcrypt('secret'),
            'role' => 'viewer',
        ]);
        Auth::login($viewer);

        $source = SeoArticle::query()->create(['id' => 404, 'site_id' => 1, 'title' => 'Source Article 404']);
        $map = SeoLinkMap::query()->create([
            'id' => 705,
            'keyword_id' => null,
            'source_article_id' => (int) $source->id,
            'link_type' => SeoLinkMapType::NeedsReview->value,
            'target_external_url' => 'https://unauthorized.test/page',
            'status' => SeoLinkMapStatus::Active->value,
            'is_semantic_eligible' => true,
        ]);

        $mockIntake = $this->createMock(PlanningDraftIntakeService::class);
        $mockIntake->expects(self::never())->method('addArticles');
        $this->app->instance(PlanningDraftIntakeService::class, $mockIntake);

        $workspace = new KeywordExternalWorkspace();
        $workspace->pushToDraft((int) $map->id);
    }

    public function test_ui_templates_and_css_contract(): void
    {
        $workspaceBlade = (string) file_get_contents(
            dirname(__DIR__, 4) . '/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/keyword-external-workspace.blade.php',
        );

        self::assertStringContainsString('keyword-external-table', $workspaceBlade);
        self::assertStringContainsString('keyword-external-col--source', $workspaceBlade);
        self::assertStringContainsString('keyword-external-col--status', $workspaceBlade);
        self::assertStringContainsString('keyword-external-col--destination', $workspaceBlade);
        self::assertStringContainsString('keyword-external-col--actions', $workspaceBlade);
        self::assertStringContainsString('setTypeFilter', $workspaceBlade);
        // Single canonical filter row: no dual rows
        self::assertSame(1, substr_count($workspaceBlade, 'link-triage-filter-bar'));

        $rowBlade = (string) file_get_contents(
            dirname(__DIR__, 4) . '/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-external-row.blade.php',
        );

        self::assertStringContainsString('keyword-external-source-article-link', $rowBlade);
        self::assertStringContainsString('pushToDraft', $rowBlade);
        self::assertStringContainsString('link-triage-action-btn--push-draft', $rowBlade);
        self::assertStringContainsString('link-triage-action-btn--in-draft', $rowBlade);
        self::assertStringContainsString('already_in_draft', $rowBlade);

        $css = (string) file_get_contents(
            dirname(__DIR__, 4) . '/seo/resources/css/keyword-workspace.css',
        );

        self::assertStringContainsString('.keyword-external-table', $css);
        self::assertStringContainsString('width: 100% !important', $css);
        self::assertStringContainsString('.keyword-external-col--source', $css);
        self::assertStringContainsString('.keyword-external-col--status', $css);
        self::assertStringContainsString('.keyword-external-col--destination', $css);
        self::assertStringContainsString('.keyword-external-col--actions', $css);
    }

    public function test_translations_contain_required_keys_in_en_and_vi(): void
    {
        $en = include dirname(__DIR__, 4) . '/seo-content-ai-compat/lang/en/filament.php';
        $vi = include dirname(__DIR__, 4) . '/seo-content-ai-compat/lang/vi/filament.php';

        self::assertArrayHasKey('keyword', $en);
        self::assertArrayHasKey('keyword', $vi);

        self::assertArrayHasKey('external_push_to_draft', $en['keyword']);
        self::assertArrayHasKey('external_push_to_draft', $vi['keyword']);

        self::assertArrayHasKey('external_filter_warning', $en['keyword']);
        self::assertArrayHasKey('external_filter_warning', $vi['keyword']);

        self::assertArrayHasKey('external_open_source_article', $en['keyword']);
        self::assertArrayHasKey('external_open_source_article', $vi['keyword']);

        self::assertArrayHasKey('external_source_article_unresolved', $en['keyword']);
        self::assertArrayHasKey('external_source_article_unresolved', $vi['keyword']);
    }

    private function bootSchemas(): void
    {
        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->string('domain')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::dropIfExists('site_meta');
        Schema::create('site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('meta_key')->nullable();
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

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

        Schema::connection('omi_seo_ai')->dropIfExists('seo_project_tasks');
        Schema::connection('omi_seo_ai')->create('seo_project_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('type')->default('new');
            $table->string('status')->default('draft');
            $table->text('description')->nullable();
            $table->text('rewrite_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
