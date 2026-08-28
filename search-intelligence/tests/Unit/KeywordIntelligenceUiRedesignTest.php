<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterIndexMcpPreviewSummary;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\CreateManualTopicClusterService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use RuntimeException;
use Tests\Support\LegacyAddonPath;
use Tests\TestCase as LaravelTestCase;

final class KeywordIntelligenceUiRedesignTest extends LaravelTestCase
{
    private const SITE_ID = 901;

    public function test_phrase_presentation_is_sentence_case_without_mutating_acronyms(): void
    {
        self::assertSame(
            'Balo anh ngữ châu âu CIE',
            KeywordPhrasePresentation::present('BALO ANH NGỮ CHÂU ÂU CIE'),
        );
        self::assertSame('May balo', KeywordPhrasePresentation::present('MAY BALO'));
    }

    public function test_navigation_defaults_to_clusters_tab_first(): void
    {
        $nav = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/Concerns/HasKeywordWorkspaceNavigation.php');
        self::assertStringContainsString("'key' => 'workspace-2'", $nav);
        self::assertLessThan(
            strpos($nav, "'key' => 'index'"),
            strpos($nav, "'key' => 'workspace-2'"),
        );

        self::assertSame(
            KeywordResource::getUrl('clusters'),
            KeywordResource::getNavigationUrl(),
        );
    }

    public function test_cluster_index_view_uses_compact_layout_markers(): void
    {
        $index = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
        ));
        self::assertStringContainsString('topic-index-page-heading', $index);
        self::assertStringContainsString('topic_page_heading', $index);
        self::assertStringContainsString('topic-index-context-card', $index);
        self::assertStringContainsString('cluster-mcp-preview', $index);
        self::assertStringContainsString('topic-index-input--search', $index);
        self::assertStringContainsString('cluster-index-row', $index);
        self::assertStringContainsString('topic_quick_create_action', $index);
        self::assertStringNotContainsString('topic_col_dna_count', $index);

        $detail = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php',
        ));
        self::assertStringContainsString('keyword-item', $detail);
        self::assertStringNotContainsString('Primary keyword', $detail);
    }

    public function test_manual_cluster_quick_create_and_duplicate_guard(): void
    {
        $this->ensureTables();

        $service = app(CreateManualTopicClusterService::class);
        $created = $service->create(self::SITE_ID, 'May túi canvas');

        self::assertSame('May túi canvas', $created['label']);
        self::assertSame(0, $created['keyword_count']);
        self::assertTrue($service->normalizedExists(self::SITE_ID, 'MAY TÚI CANVAS'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate_cluster');
        $service->create(self::SITE_ID, 'may tui canvas');
    }

    public function test_mcp_preview_subset_targets_coverage_and_tokens(): void
    {
        $summary = app(ClusterIndexMcpPreviewSummary::class);
        $topics = [
            ['name' => 'Manual planned', 'weight' => 0.0, 'state' => 'planned', 'source' => 'manual', 'priority' => 'high'],
            ['name' => 'A', 'weight' => 50.0, 'state' => 'active', 'source' => 'auto', 'priority' => null],
            ['name' => 'B', 'weight' => 30.0, 'state' => 'active', 'source' => 'auto', 'priority' => null],
            ['name' => 'C', 'weight' => 20.0, 'state' => 'active', 'source' => 'auto', 'priority' => null],
        ];

        $selected = $summary->selectPreviewSubset($topics, 80.0);
        self::assertCount(3, $selected);
        self::assertSame('Manual planned', $selected[0]['name']);
    }

    private function ensureTables(): void
    {
        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('omi_seo_ai');

        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('cluster_key', 120);
            $table->string('canonical_phrase');
            $table->string('normalized_canonical');
            $table->string('confidence')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('canonical_source')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_topic_cluster_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('cluster_key', 120);
            $table->string('alias_phrase');
            $table->string('normalized_alias')->unique();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_keyword_classifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('keyword_id')->primary();
            $table->string('normalized_text')->nullable();
            $table->string('folded_text')->nullable();
            $table->string('phrase_kind')->nullable();
            $table->string('seo_intent')->nullable();
            $table->string('cluster_key')->nullable()->index();
            $table->boolean('is_seo_keyword')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase');
            $table->string('type')->default('normal');
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keyword_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_keyword_dna', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('keyword_id');
            $table->string('cluster_key', 120)->nullable();
            $table->string('value')->nullable();
            $table->string('normalized_value')->nullable();
            $table->string('facet_type')->nullable();
            $table->string('confidence')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('title')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('omi_seo_ai')->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->unsignedBigInteger('source_article_id')->index();
            $table->unsignedBigInteger('target_article_id')->nullable()->index();
            $table->text('anchor_text')->nullable();
            $table->string('link_type')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }
}
