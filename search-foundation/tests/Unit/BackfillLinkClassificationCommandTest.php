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
use Tests\TestCase;

final class BackfillLinkClassificationCommandTest extends TestCase
{
    private int $sourceArticleId;

    private int $sameSiteArticleId;

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

        $source = SeoArticle::query()->create(['site_id' => 1, 'title' => 'Source']);
        $same = SeoArticle::query()->create(['site_id' => 1, 'title' => 'Same site']);
        $other = SeoArticle::query()->create(['site_id' => 2, 'title' => 'Other site']);
        $this->sourceArticleId = (int) $source->id;
        $this->sameSiteArticleId = (int) $same->id;
        $this->otherSiteArticleId = (int) $other->id;

        WpOption::set(ArticleEditorHistoryService::OPTION_KEY, [
            'history_step' => 20,
            'autosave_interval_seconds' => 2,
            'wiki_trust_domains' => ['example.com'],
        ]);
    }

    public function test_external_facebook_becomes_social(): void
    {
        $id = $this->insertStale('external', 'https://facebook.com/brand');

        $this->reconcile();

        $this->assertStored($id, 'social', 'social', false, null);
    }

    public function test_needs_review_facebook_becomes_social(): void
    {
        $id = $this->insertStale('needs_review', 'https://www.facebook.com/brand');

        $this->reconcile();

        $this->assertStored($id, 'social', 'social', false, null);
    }

    public function test_needs_review_zalo_becomes_social(): void
    {
        $id = $this->insertStale('needs_review', 'https://zalo.me/0901167668');

        $this->reconcile();

        $this->assertStored($id, 'social', 'social', false, null);
    }

    public function test_external_mailto_becomes_contact(): void
    {
        $id = $this->insertStale('external', 'mailto:test@example.com');

        $this->reconcile();

        $this->assertStored($id, 'contact', 'contact', false, null);
    }

    public function test_needs_review_tel_becomes_contact(): void
    {
        $id = $this->insertStale('needs_review', 'tel:+84901167668');

        $this->reconcile();

        $this->assertStored($id, 'contact', 'contact', false, null);
    }

    public function test_unresolved_managed_host_becomes_managed_cross_site(): void
    {
        $id = $this->insertStale('needs_review', 'https://site-b.test/unresolved-page');

        $this->reconcile();

        $row = $this->assertStored($id, 'managed_cross_site', 'content', true, 2);
        self::assertNull($row->target_article_id);
    }

    public function test_resolved_cross_site_article_becomes_managed_cross_site(): void
    {
        $id = $this->insertStale('external', 'https://site-b.test/resolved', [
            'target_article_id' => $this->otherSiteArticleId,
        ]);

        $this->reconcile();

        $row = $this->assertStored($id, 'managed_cross_site', 'content', true, 2);
        self::assertSame($this->otherSiteArticleId, (int) $row->target_article_id);
    }

    public function test_same_managed_site_becomes_internal(): void
    {
        $id = $this->insertStale('needs_review', 'https://site-a.test/page', [
            'target_article_id' => $this->sameSiteArticleId,
            'target_site_id' => 1,
        ]);

        $this->reconcile();

        $this->assertStored($id, 'internal', 'content', true, null);
    }

    public function test_current_trusted_domain_becomes_wiki_trust(): void
    {
        $id = $this->insertStale('needs_review', 'https://example.com/reference');

        $this->reconcile();

        $this->assertStored($id, 'wiki_trust', 'reference', true, null);
    }

    public function test_unknown_unmanaged_stays_needs_review(): void
    {
        $id = $this->insertStale('needs_review', 'https://unknown-stale.example/page');

        $this->reconcile();

        $this->assertStored($id, 'needs_review', 'other', true, null);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $id = $this->insertStale('needs_review', 'https://facebook.com/dry-run');

        $exit = Artisan::call('seo:backfill-link-classification', ['--dry-run' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('[DRY RUN]', Artisan::output());
        $row = $this->row($id);
        self::assertSame('needs_review', (string) $row->link_type);
        self::assertNull($row->destination_kind);
    }

    public function test_second_run_makes_no_further_semantic_changes(): void
    {
        $facebook = $this->insertStale('external', 'https://facebook.com/twice');
        $managed = $this->insertStale('needs_review', 'https://site-b.test/twice');
        $trusted = $this->insertStale('external', 'https://docs.example.com/guide');
        $unknown = $this->insertStale('external', 'https://still-unknown.example/x');
        $alreadySocial = $this->insertStale('social', 'https://t.me/channel', [
            'destination_kind' => 'social',
            'is_semantic_eligible' => false,
            'target_site_id' => null,
        ]);

        $this->reconcile();
        $afterFirst = $this->semanticSnapshot();

        $exit = Artisan::call('seo:backfill-link-classification');
        $output = Artisan::output();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Updated 0 /', $output);
        self::assertSame($afterFirst, $this->semanticSnapshot());
        $this->assertStored($facebook, 'social', 'social', false, null);
        $this->assertStored($managed, 'managed_cross_site', 'content', true, 2);
        $this->assertStored($trusted, 'wiki_trust', 'reference', true, null);
        $this->assertStored($unknown, 'needs_review', 'other', true, null);
        $this->assertStored($alreadySocial, 'social', 'social', false, null);
    }

    private function reconcile(): void
    {
        $exit = Artisan::call('seo:backfill-link-classification');
        self::assertSame(0, $exit, Artisan::output());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function insertStale(string $linkType, string $url, array $extra = []): int
    {
        return (int) DB::connection('omi_seo_ai')->table('seo_link_maps')->insertGetId(array_merge([
            'source_article_id' => $this->sourceArticleId,
            'target_external_url' => $url,
            'anchor_text' => 'reconcile',
            'link_type' => $linkType,
            'destination_kind' => null,
            'is_semantic_eligible' => null,
            'target_site_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    private function assertStored(
        int $id,
        string $linkType,
        string $destinationKind,
        bool $semantic,
        ?int $targetSiteId,
    ): object {
        $row = $this->row($id);
        self::assertSame($linkType, (string) $row->link_type);
        self::assertSame($destinationKind, (string) $row->destination_kind);
        self::assertSame($semantic, filter_var($row->is_semantic_eligible, FILTER_VALIDATE_BOOLEAN));
        $storedSite = $row->target_site_id === null || $row->target_site_id === ''
            ? null
            : (int) $row->target_site_id;
        self::assertSame($targetSiteId, $storedSite > 0 ? $storedSite : null);

        return $row;
    }

    private function row(int $id): object
    {
        $row = DB::connection('omi_seo_ai')->table('seo_link_maps')->where('id', $id)->first();
        self::assertNotNull($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function semanticSnapshot(): array
    {
        return DB::connection('omi_seo_ai')
            ->table('seo_link_maps')
            ->orderBy('id')
            ->get(['id', 'link_type', 'destination_kind', 'is_semantic_eligible', 'target_site_id', 'target_article_id'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'link_type' => (string) $row->link_type,
                'destination_kind' => $row->destination_kind === null ? null : (string) $row->destination_kind,
                'is_semantic_eligible' => $row->is_semantic_eligible === null
                    ? null
                    : filter_var($row->is_semantic_eligible, FILTER_VALIDATE_BOOLEAN),
                'target_site_id' => $row->target_site_id === null || (int) $row->target_site_id <= 0
                    ? null
                    : (int) $row->target_site_id,
                'target_article_id' => $row->target_article_id === null ? null : (int) $row->target_article_id,
            ])
            ->all();
    }

    private function bootSchemas(): void
    {
        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('domain')->nullable();
            $table->string('status')->nullable();
            $table->boolean('ssl')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name', 191)->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload', 20)->default('yes');
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
            $table->boolean('is_semantic_eligible')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }
}
