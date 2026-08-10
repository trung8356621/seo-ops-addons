<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankCheckRun;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordRankCheckService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class KeywordRankCheckServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('omi_seo_ai');
        Schema::connection('omi_seo_ai')->create('keyword_rank_check_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('rank_group_id')->nullable();
            $table->string('connection_hash', 64)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('run_type', 32)->default('batch');
            $table->unsignedInteger('total_keywords')->default(0);
            $table->unsignedInteger('processed_keywords')->default(0);
            $table->unsignedInteger('failed_keywords')->default(0);
            $table->string('provider')->default('serpapi');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function test_reconcile_clears_stale_running_group_run_with_no_progress(): void
    {
        KeywordRankCheckRun::query()->create([
            'rank_group_id' => 5,
            'provider' => 'serpapi',
            'status' => 'running',
            'total_keywords' => 21,
            'processed_keywords' => 0,
            'failed_keywords' => 0,
            'started_at' => now()->subMinutes(5),
        ]);

        $reconciled = app(KeywordRankCheckService::class)->reconcileStaleRuns(5, 'serpapi');

        $this->assertSame(1, $reconciled);
        $this->assertSame('failed', KeywordRankCheckRun::query()->value('status'));
    }

    public function test_reconcile_does_not_touch_recent_running_run(): void
    {
        KeywordRankCheckRun::query()->create([
            'rank_group_id' => 5,
            'provider' => 'serpapi',
            'status' => 'running',
            'total_keywords' => 21,
            'processed_keywords' => 0,
            'failed_keywords' => 0,
            'started_at' => now()->subMinute(),
        ]);

        $reconciled = app(KeywordRankCheckService::class)->reconcileStaleRuns(5, 'serpapi');

        $this->assertSame(0, $reconciled);
        $this->assertSame('running', KeywordRankCheckRun::query()->value('status'));
    }
}
