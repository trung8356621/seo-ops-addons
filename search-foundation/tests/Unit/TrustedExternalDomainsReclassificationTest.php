<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit;

use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Omnichannel\Addons\SearchFoundation\Console\BackfillLinkClassificationCommand;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\LinkClassificationReconciliationService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordExternalRelationshipReadModel;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Tests\TestCase;

final class TrustedExternalDomainsReclassificationTest extends TestCase
{
    private int $sourceArticleId;
    private int $otherSiteArticleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSchemas();
        Artisan::registerCommand($this->app->make(BackfillLinkClassificationCommand::class));

        DB::table('sites')->insert([
            [
                'id' => 1,
                'domain' => 'site-a.test',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'domain' => 'site-b.test',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $source = SeoArticle::query()->create(['id' => 10, 'site_id' => 1, 'title' => 'Source Article']);
        $other = SeoArticle::query()->create(['id' => 20, 'site_id' => 2, 'title' => 'Other Article']);
        $this->sourceArticleId = (int) $source->id;
        $this->otherSiteArticleId = (int) $other->id;

        WpOption::set(ArticleEditorHistoryService::OPTION_KEY, [
            'history_step' => 20,
            'autosave_interval_seconds' => 2,
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu'],
        ]);

        $historyService = app(ArticleEditorHistoryService::class);
        $historyService->setReconciliationFingerprint(
            $historyService->computeTrustedDomainsFingerprint(['wikipedia.org', '*.gov', '*.edu'])
        );
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance(LinkClassificationReconciliationService::class);
        WpOption::clearRequestCache();
        parent::tearDown();
    }

    public function test_saving_identical_normalized_trusted_domains_triggers_no_reconciliation(): void
    {
        $mock = $this->createMock(LinkClassificationReconciliationService::class);
        $mock->expects($this->never())->method('reconcile');
        $this->app->instance(LinkClassificationReconciliationService::class, $mock);

        $service = app(ArticleEditorHistoryService::class);
        // Saving same domains with different casing / whitespace / ordering
        $service->saveSettings([
            'wiki_trust_domains' => ['*.gov', 'wikipedia.org', '*.edu'],
        ]);

        $this->app->forgetInstance(LinkClassificationReconciliationService::class);
    }

    public function test_adding_trusted_domain_reclassifies_needs_review_and_external_to_wikitrust(): void
    {
        $id1 = $this->insertLinkMap('needs_review', 'https://trustme.org/article');
        $id2 = $this->insertLinkMap('external', 'https://www.trustme.org/page');

        $this->assertStored($id1, 'needs_review');
        $this->assertStored($id2, 'external');

        // Add trustme.org to settings
        $service = app(ArticleEditorHistoryService::class);
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu', 'trustme.org'],
        ]);

        $this->assertStored($id1, 'wiki_trust', 'reference');
        $this->assertStored($id2, 'wiki_trust', 'reference');
    }

    public function test_removing_trusted_domain_reclassifies_wikitrust_to_needs_review(): void
    {
        // Initially wikipedia.org is trusted
        $id = $this->insertLinkMap('wiki_trust', 'https://en.wikipedia.org/wiki/Topic');
        $this->assertStored($id, 'wiki_trust');

        // Remove wikipedia.org
        $service = app(ArticleEditorHistoryService::class);
        $service->saveSettings([
            'wiki_trust_domains' => ['*.gov', '*.edu'],
        ]);

        $this->assertStored($id, 'needs_review', 'other');
    }

    public function test_managed_cross_site_remains_managed_cross_site_even_if_hostname_matches_trusted_list(): void
    {
        // Hostname site-b.test is a managed site
        $id = $this->insertLinkMap('managed_cross_site', 'https://site-b.test/post', [
            'target_article_id' => $this->otherSiteArticleId,
            'target_site_id' => 2,
        ]);

        // Add site-b.test to trusted domains
        $service = app(ArticleEditorHistoryService::class);
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu', 'site-b.test'],
        ]);

        $this->assertStored($id, 'managed_cross_site', 'content');
        $row = DB::connection('omi_seo_ai')->table('seo_link_maps')->where('id', $id)->first();
        self::assertSame(2, (int) $row->target_site_id);
    }

    public function test_internal_remains_internal(): void
    {
        $id = $this->insertLinkMap('internal', 'https://site-a.test/my-page', [
            'target_article_id' => $this->sourceArticleId,
            'target_site_id' => 1,
        ]);

        $service = app(ArticleEditorHistoryService::class);
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', 'site-a.test'],
        ]);

        $this->assertStored($id, 'internal', 'content');
    }

    public function test_social_remains_social(): void
    {
        $id = $this->insertLinkMap('social', 'https://facebook.com/my-page');

        $service = app(ArticleEditorHistoryService::class);
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', 'facebook.com'],
        ]);

        $this->assertStored($id, 'social', 'social');
    }

    public function test_contact_remains_contact(): void
    {
        $id = $this->insertLinkMap('contact', 'mailto:contact@gov.vn');

        $service = app(ArticleEditorHistoryService::class);
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', '*.vn', 'gov.vn'],
        ]);

        $this->assertStored($id, 'contact', 'contact');
    }

    public function test_backfill_cli_continues_to_use_same_reconciliation_ssot(): void
    {
        $id = $this->insertLinkMap('needs_review', 'https://en.wikipedia.org/wiki/Example');

        Artisan::call('seo:backfill-link-classification');

        $this->assertStored($id, 'wiki_trust', 'reference');
    }

    public function test_external_read_model_and_counts_reflect_updated_persisted_result(): void
    {
        $id1 = $this->insertLinkMap('needs_review', 'https://docs.example.org/guide');
        $id2 = $this->insertLinkMap('wiki_trust', 'https://en.wikipedia.org/wiki/Doc');

        $readModel = new KeywordExternalRelationshipReadModel();
        $countsBefore = $readModel->categoryCounts(1);
        self::assertSame(1, $countsBefore['reference']);
        self::assertSame(1, $countsBefore['needs_review']);

        // Add example.org to trusted domains
        $service = app(ArticleEditorHistoryService::class);
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu', 'example.org'],
        ]);

        $countsAfter = $readModel->categoryCounts(1);
        self::assertSame(2, $countsAfter['reference'], 'example.org became reference');
        self::assertSame(0, $countsAfter['needs_review'], 'needs_review became 0');
    }

    public function test_existing_trusted_settings_predate_fingerprint_runs_reconciliation_and_stores_fingerprint(): void
    {
        // 1. Existing trusted settings predate fingerprint:
        WpOption::where('option_name', ArticleEditorHistoryService::RECONCILIATION_FINGERPRINT_OPTION_KEY)->delete();
        WpOption::clearRequestCache();

        $service = app(ArticleEditorHistoryService::class);
        self::assertNull($service->getReconciliationFingerprint());

        $mock = $this->createMock(LinkClassificationReconciliationService::class);
        $mock->expects($this->once())->method('reconcile');
        $this->app->instance(LinkClassificationReconciliationService::class, $mock);

        // Save identical list of domains
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu'],
        ]);

        $this->app->forgetInstance(LinkClassificationReconciliationService::class);
        self::assertNotNull($service->getReconciliationFingerprint());
        self::assertSame(
            $service->computeTrustedDomainsFingerprint(['wikipedia.org', '*.gov', '*.edu']),
            $service->getReconciliationFingerprint()
        );
    }

    public function test_existing_stale_needs_review_natoli_vn_reclassifies_to_wikitrust_when_fingerprint_missing(): void
    {
        // 2. Existing stale NeedsReview natoli.vn:
        WpOption::set(ArticleEditorHistoryService::OPTION_KEY, [
            'history_step' => 20,
            'autosave_interval_seconds' => 2,
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu', 'natoli.vn'],
        ]);
        WpOption::where('option_name', ArticleEditorHistoryService::RECONCILIATION_FINGERPRINT_OPTION_KEY)->delete();
        WpOption::clearRequestCache();

        $service = app(ArticleEditorHistoryService::class);
        self::assertNull($service->getReconciliationFingerprint());

        // Stale row created before reconciliation hook existed
        $id = $this->insertLinkMap('needs_review', 'https://natoli.vn/ao-khoac-nam');
        $this->assertStored($id, 'needs_review');

        // User clicks "Save settings" without changing the list
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu', 'natoli.vn'],
        ]);

        // Row becomes wiki_trust / reference
        $this->assertStored($id, 'wiki_trust', 'reference');
        self::assertSame(
            $service->computeTrustedDomainsFingerprint(['wikipedia.org', '*.gov', '*.edu', 'natoli.vn']),
            $service->getReconciliationFingerprint()
        );
    }

    public function test_add_or_remove_trusted_domain_changes_fingerprint_and_reconciles(): void
    {
        // 4. Add/remove trusted domain:
        $service = app(ArticleEditorHistoryService::class);
        $initialFingerprint = $service->getReconciliationFingerprint();
        self::assertNotNull($initialFingerprint);

        // Add domain
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu', 'natoli.vn'],
        ]);
        $addedFingerprint = $service->getReconciliationFingerprint();
        self::assertNotNull($addedFingerprint);
        self::assertNotSame($initialFingerprint, $addedFingerprint);
        self::assertSame(
            $service->computeTrustedDomainsFingerprint(['wikipedia.org', '*.gov', '*.edu', 'natoli.vn']),
            $addedFingerprint
        );

        // Remove domain
        $service->saveSettings([
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu'],
        ]);
        $removedFingerprint = $service->getReconciliationFingerprint();
        self::assertSame($initialFingerprint, $removedFingerprint);
    }

    public function test_reconciliation_failure_does_not_advance_fingerprint(): void
    {
        // 5. Reconciliation failure:
        $service = app(ArticleEditorHistoryService::class);
        $initialFingerprint = $service->getReconciliationFingerprint();
        self::assertNotNull($initialFingerprint);

        $mock = $this->createMock(LinkClassificationReconciliationService::class);
        $mock->expects($this->once())
            ->method('reconcile')
            ->willThrowException(new \RuntimeException('Database failure during reconciliation'));
        $this->app->instance(LinkClassificationReconciliationService::class, $mock);

        $caught = false;
        try {
            $service->saveSettings([
                'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu', 'unreconciled.org'],
            ]);
        } catch (\RuntimeException $e) {
            $caught = true;
            self::assertSame('Database failure during reconciliation', $e->getMessage());
        }

        $this->app->forgetInstance(LinkClassificationReconciliationService::class);
        self::assertTrue($caught, 'Expected RuntimeException was thrown');

        // Fingerprint must NOT advance to the new domain's fingerprint
        self::assertSame($initialFingerprint, $service->getReconciliationFingerprint());
    }

    public function test_history_step_and_autosave_only_change_leaves_fingerprint_unchanged_and_skips_reconciliation(): void
    {
        // 6. history_step/autosave-only change:
        $service = app(ArticleEditorHistoryService::class);
        $fingerprintBefore = $service->getReconciliationFingerprint();
        self::assertNotNull($fingerprintBefore);

        $mock = $this->createMock(LinkClassificationReconciliationService::class);
        $mock->expects($this->never())->method('reconcile');
        $this->app->instance(LinkClassificationReconciliationService::class, $mock);

        // 1. Save with only history_step / autosave changed while passing same wiki_trust_domains
        $service->saveSettings([
            'history_step' => 45,
            'autosave_interval_seconds' => 15,
            'wiki_trust_domains' => ['wikipedia.org', '*.gov', '*.edu'],
        ]);

        self::assertSame(45, $service->getHistoryStep());
        self::assertSame($fingerprintBefore, $service->getReconciliationFingerprint());

        // 2. Save with wiki_trust_domains omitted entirely
        $service->saveSettings([
            'history_step' => 50,
            'autosave_interval_seconds' => 5,
        ]);

        self::assertSame(50, $service->getHistoryStep());
        self::assertSame($fingerprintBefore, $service->getReconciliationFingerprint());

        $this->app->forgetInstance(LinkClassificationReconciliationService::class);
    }

    private function insertLinkMap(string $linkType, string $url, array $extra = []): int
    {
        return (int) DB::connection('omi_seo_ai')
            ->table('seo_link_maps')
            ->insertGetId(array_merge([
                'source_article_id' => $this->sourceArticleId,
                'target_external_url' => $url,
                'anchor_text' => 'anchor',
                'link_type' => $linkType,
                'destination_kind' => match ($linkType) {
                    'social' => 'social',
                    'contact' => 'contact',
                    'wiki_trust' => 'reference',
                    'internal', 'managed_cross_site' => 'content',
                    default => 'other',
                },
                'is_semantic_eligible' => true,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ], $extra));
    }

    private function assertStored(int $id, string $expectedType, ?string $expectedKind = null): void
    {
        $row = DB::connection('omi_seo_ai')->table('seo_link_maps')->where('id', $id)->first();
        self::assertNotNull($row, "Row {$id} must exist");
        self::assertSame($expectedType, $row->link_type, "Row {$id} link_type mismatch");
        if ($expectedKind !== null) {
            self::assertSame($expectedKind, $row->destination_kind, "Row {$id} destination_kind mismatch");
        }
    }

    private function bootSchemas(): void
    {
        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value');
            $table->string('autoload')->default('yes');
            $table->timestamps();
        });

        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->unique();
            $table->string('status')->default('active');
            $table->timestamps();
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
    }
}
