<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;
use Omnichannel\Addons\AiPrompt\Services\AiTokenUsageAnalyticsService;
use Omnichannel\Addons\AiPrompt\Services\AiUsageTaxonomy;
use Tests\TestCase;

final class AiTokenUsageAnalyticsServiceTest extends TestCase
{
    private AiTokenUsageAnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();

        $conn = (new PromptResultRoutingAttempt())->getConnectionName() ?: 'omi_seo_ai';

        if (! Schema::connection($conn)->hasTable('prompt_result_routing_attempts')) {
            Schema::connection($conn)->create('prompt_result_routing_attempts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_result_id')->nullable();
                $table->string('addon', 32)->nullable()->index();
                $table->string('module', 64)->nullable()->index();
                $table->string('action', 64)->nullable()->index();
                $table->string('provider', 64)->nullable();
                $table->string('model', 128)->nullable();
                $table->string('status', 32)->default('pending');
                $table->boolean('attempted')->default(true);
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->unsignedInteger('total_tokens')->nullable();
                $table->unsignedSmallInteger('attempt_sequence')->default(1);
                $table->timestamps();
            });
        }

        PromptResultRoutingAttempt::query()->delete();

        $this->analytics = app(AiTokenUsageAnalyticsService::class);
    }

    public function test_aggregates_seo_and_seeding_separately(): void
    {
        // SEO: Content Project (100 in, 50 out = 150 total)
        PromptResultRoutingAttempt::create([
            'addon' => AiUsageTaxonomy::ADDON_SEO,
            'module' => AiUsageTaxonomy::MODULE_CONTENT_PROJECT,
            'action' => AiUsageTaxonomy::ACTION_OUTLINE,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'success',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'created_at' => Carbon::now(),
        ]);

        // SEO: Topic (200 in, 100 out = 300 total)
        PromptResultRoutingAttempt::create([
            'addon' => AiUsageTaxonomy::ADDON_SEO,
            'module' => AiUsageTaxonomy::MODULE_TOPIC,
            'action' => AiUsageTaxonomy::ACTION_CLUSTERING,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'success',
            'input_tokens' => 200,
            'output_tokens' => 100,
            'total_tokens' => 300,
            'created_at' => Carbon::now(),
        ]);

        // Seeding: Generate Comment (50 in, 30 out = 80 total)
        PromptResultRoutingAttempt::create([
            'addon' => AiUsageTaxonomy::ADDON_SEEDING,
            'module' => AiUsageTaxonomy::MODULE_SEEDING,
            'action' => AiUsageTaxonomy::ACTION_GENERATE_COMMENT,
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'status' => 'success',
            'input_tokens' => 50,
            'output_tokens' => 30,
            'total_tokens' => 80,
            'created_at' => Carbon::now(),
        ]);

        $summary = $this->analytics->getSummary('today', 'all');

        // SEO: 150 + 300 = 450 tokens, 2 calls
        self::assertSame(450, $summary['seo']['tokens']);
        self::assertSame(2, $summary['seo']['calls']);

        // Seeding: 80 tokens, 1 call
        self::assertSame(80, $summary['seeding']['tokens']);
        self::assertSame(1, $summary['seeding']['calls']);

        // Grand Total: 450 + 80 = 530 tokens, 3 calls
        self::assertSame(530, $summary['total_tokens']);
        self::assertSame(3, $summary['total_calls']);
    }

    public function test_retry_and_fallback_physical_attempts_are_counted(): void
    {
        // 1 request chính phát sinh 2 physical attempts:
        // Attempt 1: provider A thất bại nhưng vẫn tiêu tốn input tokens (preflight/partial)
        PromptResultRoutingAttempt::create([
            'prompt_result_id' => 999,
            'addon' => AiUsageTaxonomy::ADDON_SEO,
            'module' => AiUsageTaxonomy::MODULE_CONTENT_PROJECT,
            'action' => AiUsageTaxonomy::ACTION_ARTICLE,
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'status' => 'failed',
            'input_tokens' => 500,
            'output_tokens' => 0,
            'total_tokens' => 500,
            'attempt_sequence' => 1,
            'created_at' => Carbon::now(),
        ]);

        // Attempt 2: fallback sang provider B thành công
        PromptResultRoutingAttempt::create([
            'prompt_result_id' => 999,
            'addon' => AiUsageTaxonomy::ADDON_SEO,
            'module' => AiUsageTaxonomy::MODULE_CONTENT_PROJECT,
            'action' => AiUsageTaxonomy::ACTION_ARTICLE,
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'status' => 'success',
            'input_tokens' => 500,
            'output_tokens' => 400,
            'total_tokens' => 900,
            'attempt_sequence' => 2,
            'created_at' => Carbon::now(),
        ]);

        $summary = $this->analytics->getSummary('today', 'seo');

        // Cả 2 physical attempts đều là lượt dùng AI thật: 500 + 900 = 1400 tokens, 2 calls
        self::assertSame(1400, $summary['seo']['tokens']);
        self::assertSame(2, $summary['seo']['calls']);
    }

    public function test_attempt_without_usage_does_not_create_fake_tokens(): void
    {
        PromptResultRoutingAttempt::create([
            'addon' => AiUsageTaxonomy::ADDON_SEO,
            'module' => AiUsageTaxonomy::MODULE_SEO_AUDIT,
            'action' => AiUsageTaxonomy::ACTION_AUDIT,
            'provider' => 'custom',
            'model' => 'unknown',
            'status' => 'failed',
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'created_at' => Carbon::now(),
        ]);

        $summary = $this->analytics->getSummary('today', 'all');

        self::assertSame(0, $summary['seo']['tokens']);
        self::assertSame(1, $summary['seo']['calls']);
        self::assertSame(0, $summary['total_tokens']);
    }

    public function test_table_data_hierarchy_addon_and_module_breakdown(): void
    {
        PromptResultRoutingAttempt::create([
            'addon' => AiUsageTaxonomy::ADDON_SEO,
            'module' => AiUsageTaxonomy::MODULE_CONTENT_PROJECT,
            'action' => AiUsageTaxonomy::ACTION_ARTICLE,
            'input_tokens' => 100,
            'output_tokens' => 200,
            'total_tokens' => 300,
            'created_at' => Carbon::now(),
        ]);

        PromptResultRoutingAttempt::create([
            'addon' => AiUsageTaxonomy::ADDON_SEEDING,
            'module' => AiUsageTaxonomy::MODULE_SEEDING,
            'action' => AiUsageTaxonomy::ACTION_GENERATE_COMMENT,
            'input_tokens' => 40,
            'output_tokens' => 60,
            'total_tokens' => 100,
            'created_at' => Carbon::now(),
        ]);

        $table = $this->analytics->getTableData('today', 'all');

        // Bảng gồm cấp Addon (SEO, Seeding)
        self::assertArrayHasKey('seo', $table);
        self::assertArrayHasKey('seeding', $table);

        // Kiểm tra SEO tổng và các module con
        $seoRow = $table['seo'];
        self::assertSame(300, $seoRow['total_tokens']);
        self::assertSame(1, $seoRow['calls']);
        self::assertNotEmpty($seoRow['children']);

        // Tìm module content_project trong children
        $contentProjMod = collect($seoRow['children'])->firstWhere('key', 'content_project');
        self::assertNotNull($contentProjMod);
        self::assertSame(300, $contentProjMod['total_tokens']);

        // Kiểm tra Seeding
        $seedingRow = $table['seeding'];
        self::assertSame(100, $seedingRow['total_tokens']);
        self::assertSame(1, $seedingRow['calls']);
    }

    public function test_daily_trend_contains_only_seo_and_seeding_series(): void
    {
        PromptResultRoutingAttempt::create([
            'addon' => AiUsageTaxonomy::ADDON_SEO,
            'module' => AiUsageTaxonomy::MODULE_CONTENT_PROJECT,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'created_at' => Carbon::now(),
        ]);

        PromptResultRoutingAttempt::create([
            'addon' => AiUsageTaxonomy::ADDON_SEEDING,
            'module' => AiUsageTaxonomy::MODULE_SEEDING,
            'input_tokens' => 30,
            'output_tokens' => 20,
            'total_tokens' => 50,
            'created_at' => Carbon::now(),
        ]);

        $trend = $this->analytics->getDailyTrend('7d', 'all');

        self::assertArrayHasKey('labels', $trend);
        self::assertArrayHasKey('seo_series', $trend);
        self::assertArrayHasKey('seeding_series', $trend);
        self::assertArrayHasKey('total_series', $trend);

        // Tuyệt đối không có series/cột liên quan đến cost
        self::assertArrayNotHasKey('cost_series', $trend);
        self::assertArrayNotHasKey('cost', $trend);

        $lastIdx = count($trend['labels']) - 1;
        self::assertSame(150, $trend['seo_series'][$lastIdx]);
        self::assertSame(50, $trend['seeding_series'][$lastIdx]);
        self::assertSame(200, $trend['total_series'][$lastIdx]);
    }
}
