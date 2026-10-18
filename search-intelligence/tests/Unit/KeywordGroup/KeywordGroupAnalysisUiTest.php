<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\KeywordGroup;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Jobs\PollKeywordGroupAnalysisJob;
use Omnichannel\Addons\SearchIntelligence\Jobs\RefreshKeywordGroupsJob;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupAnalysisRun;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupAnalysisDispatcher;
use Tests\TestCase;

final class KeywordGroupAnalysisUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('omi_seo_ai');
        $runs = require dirname(__DIR__, 3).'/database/migrations/2026_10_17_120000_create_seo_keyword_group_analysis_runs.php';
        $runs->up();
        $byLanguage = require dirname(__DIR__, 3).'/database/migrations/2026_10_17_180000_scope_keyword_group_analysis_runs_by_language.php';
        $byLanguage->up();
    }

    public function test_enqueue_is_queued_once_and_sync_driver_does_not_dispatch(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        $dispatcher = new KeywordGroupAnalysisDispatcher();

        self::assertSame('queued', $dispatcher->enqueue(8, null));
        self::assertSame('busy', $dispatcher->enqueue(8, null));
        Bus::assertDispatchedTimes(PollKeywordGroupAnalysisJob::class, 1);
        Bus::assertNotDispatched(RefreshKeywordGroupsJob::class);
        self::assertSame('queued', $dispatcher->status(8)['phase']);
        self::assertTrue($dispatcher->status(8)['active']);
        self::assertFalse($dispatcher->status(8)['worker_warning']);

        SeoKeywordGroupAnalysisRun::query()->where('site_id', 8)->update(['phase' => 'failed', 'last_error' => 'worker heartbeat expired']);
        $failed = $dispatcher->status(8);
        self::assertSame('failed', $failed['phase']);
        self::assertTrue($failed['retry']);
        self::assertSame('worker heartbeat expired', $failed['error']);

        SeoKeywordGroupAnalysisRun::query()->where('site_id', 8)->update(['phase' => 'stale', 'last_error' => 'dataset changed before finalize']);
        self::assertTrue($dispatcher->status(8)['retry']);
        app(\Illuminate\Bus\UniqueLock::class)->release(new PollKeywordGroupAnalysisJob(8));
        self::assertSame('queued', $dispatcher->enqueue(8, null));
        Bus::assertDispatchedTimes(PollKeywordGroupAnalysisJob::class, 2);

        config(['queue.default' => 'sync']);
        SeoKeywordGroupAnalysisRun::query()->where('site_id', 8)->update(['phase' => 'failed']);
        self::assertSame('sync_driver', $dispatcher->enqueue(8, null));
        Bus::assertDispatchedTimes(PollKeywordGroupAnalysisJob::class, 2);
        self::assertSame('failed', SeoKeywordGroupAnalysisRun::query()->where('site_id', 8)->value('phase'));
    }

    public function test_status_survives_reload_and_warns_when_the_seo_job_is_late(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        $dispatcher = new KeywordGroupAnalysisDispatcher();
        $dispatcher->enqueue(8, null);
        $again = new KeywordGroupAnalysisDispatcher();
        self::assertSame('queued', $again->status(8)['phase']);

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function ($table): void {
                $table->id();
                $table->string('queue');
                $table->text('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        DB::table('jobs')->insert([
            'queue' => 'seo',
            'payload' => 'PollKeywordGroupAnalysisJob siteId";i:8;',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinute()->getTimestamp(),
            'created_at' => now()->subMinute()->getTimestamp(),
        ]);

        self::assertTrue($dispatcher->status(8)['worker_warning']);
        self::assertNotSame('finalized', $dispatcher->status(8)['phase']);
    }

    public function test_orphaned_run_is_requeued_and_keeps_the_python_run_id(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function ($table): void {
                $table->id();
                $table->string('queue');
                $table->text('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        SeoKeywordGroupAnalysisRun::query()->create([
            'site_id' => 8,
            'run_id' => 'run-keep',
            'dataset_revision' => 3,
            'dataset_hash' => str_repeat('a', 64),
            'fingerprint' => str_repeat('b', 64),
            'algorithm' => 'hybrid_semantic_lexical_v1',
            'phase' => 'running',
        ]);
        $dispatcher = new KeywordGroupAnalysisDispatcher();
        $status = $dispatcher->status(8);
        self::assertFalse($status['active']);
        self::assertTrue($status['retry']);
        self::assertSame('run-keep', $status['run_id']);

        self::assertSame('queued', $dispatcher->enqueue(8, null));
        self::assertSame('run-keep', SeoKeywordGroupAnalysisRun::query()->where('site_id', 8)->value('run_id'));
        self::assertSame('queued', SeoKeywordGroupAnalysisRun::query()->where('site_id', 8)->value('phase'));
        Bus::assertDispatchedTimes(PollKeywordGroupAnalysisJob::class, 1);
    }

    public function test_language_runs_do_not_overwrite_each_other(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        $dispatcher = new KeywordGroupAnalysisDispatcher();

        self::assertSame('queued', $dispatcher->enqueue(8, 'vi'));
        self::assertSame('queued', $dispatcher->enqueue(8, 'en'));
        self::assertSame('queued', $dispatcher->status(8, 'vi')['phase']);
        self::assertSame('queued', $dispatcher->status(8, 'en')['phase']);
        self::assertSame('idle', $dispatcher->status(8, 'fr')['phase']);
        self::assertSame(2, SeoKeywordGroupAnalysisRun::query()->where('site_id', 8)->count());

        SeoKeywordGroupAnalysisRun::query()->where('site_id', 8)->where('language', 'vi')->update([
            'phase' => 'finalized',
            'group_count' => 869,
            'member_count' => 2505,
        ]);
        self::assertSame('busy', $dispatcher->enqueue(8, 'en'));
        self::assertSame('finalized', SeoKeywordGroupAnalysisRun::query()->where('language', 'vi')->value('phase'));
        self::assertSame(869, (int) SeoKeywordGroupAnalysisRun::query()->where('language', 'vi')->value('group_count'));
        self::assertSame('queued', SeoKeywordGroupAnalysisRun::query()->where('language', 'en')->value('phase'));
    }

    public function test_json_encoded_queue_payload_keeps_the_language_run_active(): void
    {
        config(['queue.default' => 'database']);
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function ($table): void {
                $table->id();
                $table->string('queue');
                $table->text('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        SeoKeywordGroupAnalysisRun::query()->create([
            'site_id' => 8,
            'run_id' => 'run-en',
            'language' => 'en',
            'dataset_revision' => 2,
            'dataset_hash' => str_repeat('c', 64),
            'fingerprint' => str_repeat('d', 64),
            'algorithm' => 'hybrid_semantic_lexical_v1',
            'phase' => 'running',
        ]);
        $serialized = 'O:1:"X":2:{s:6:"siteId";i:8;s:8:"language";s:2:"en";}';
        DB::table('jobs')->insert([
            'queue' => 'seo',
            'payload' => json_encode(['displayName' => PollKeywordGroupAnalysisJob::class, 'command' => $serialized], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $status = (new KeywordGroupAnalysisDispatcher())->status(8, 'en');
        self::assertTrue($status['active']);
        self::assertFalse($status['retry']);
        self::assertFalse((new KeywordGroupAnalysisDispatcher())->status(8, 'vi')['active']);
    }
}
