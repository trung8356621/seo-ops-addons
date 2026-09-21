<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use Omnichannel\Addons\ContentProjects\Services\WriterMonthlyCapacityGate;
use PHPUnit\Framework\TestCase;

/**
 * Live MySQL assertions: within-month move ignores project/item site mix;
 * task.site_id is preserved across writers.
 */
final class SeoProjectTaskMoveDomainNeutralLiveDbTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $root = getcwd() ?: '';
        $bootstrap = $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
        if (! is_file($bootstrap)) {
            $this->markTestSkipped('Run phpunit from omnichannel-client so bootstrap/app.php is available.');
        }

        $app = require $bootstrap;
        $app->make(Kernel::class)->bootstrap();
        $this->restoreMysqlDatabasesFromDotEnv($root);
        $this->bindWriterMonthlyCapacity(30);

        try {
            $conn = SeoProject::query()->getConnection();
            if ($conn->getDriverName() !== 'mysql') {
                $this->markTestSkipped('Requires mysql omi_seo_ai, got '.$conn->getDriverName());
            }
            if (! $conn->getSchemaBuilder()->hasTable('seo_projects')
                || ! $conn->getSchemaBuilder()->hasTable('seo_project_tasks')
            ) {
                $this->markTestSkipped('seo_projects tables missing.');
            }
            $conn->select('select 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Cannot open omi_seo_ai: '.$e->getMessage());
        }
    }

    public function test_live_db_options_include_all_aug_execution_projects_regardless_of_site(): void
    {
        $conn = SeoProject::query()->getConnection();
        $conn->beginTransaction();

        try {
            $source = $this->createMonthly(99201, null, '2026-08-01', 'Src neutral');
            $writerB = $this->createMonthly(99202, null, '2026-08-01', 'Writer B site8');
            $writerC = $this->createMonthly(99203, null, '2026-08-01', 'Writer C site12');
            $writerD = $this->createMonthly(99204, 99, '2026-08-01', 'Writer D legacy99');
            $sep = $this->createMonthly(99205, null, '2026-09-01', 'Writer E Sep');
            $draft = SeoProject::query()->create([
                'name' => 'Draft Aug '.uniqid('', true),
                'user_id' => 99206,
                'site_id' => null,
                'month' => '2026-08-01',
                'status' => SeoProject::STATUS_DRAFT,
                'kind' => SeoProject::KIND_MONTHLY,
                'total_tasks' => 0,
            ]);
            $archived = $this->createMonthly(99207, null, '2026-08-01', 'Archive Aug');
            $archived->forceFill(['archived_at' => now()])->save();

            $this->createTask($source, 7, SeoProjectTask::STATUS_REVIEWING, 88001);
            $this->createTask($writerB, 8, SeoProjectTask::STATUS_PENDING);
            $this->createTask($writerC, 12, SeoProjectTask::STATUS_PENDING);

            $options = app(SeoProjectTaskMoveService::class)->moveTargetOptions($source->fresh() ?? $source);
            $ids = array_map('intval', array_keys($options));

            self::assertContains((int) $writerB->id, $ids);
            self::assertContains((int) $writerC->id, $ids);
            self::assertContains((int) $writerD->id, $ids);
            self::assertNotContains((int) $sep->id, $ids);
            self::assertNotContains((int) $draft->id, $ids);
            self::assertNotContains((int) $archived->id, $ids);
            self::assertNotContains((int) $source->id, $ids);
        } finally {
            $conn->rollBack();
        }
    }

    public function test_live_db_options_exclude_projects_at_max_30_items(): void
    {
        $conn = SeoProject::query()->getConnection();
        $conn->beginTransaction();

        try {
            $source = $this->createMonthly(99401, null, '2026-08-01', 'Src packing');
            $this->createTask($source, 7, SeoProjectTask::STATUS_PENDING);

            $full = $this->createMonthly(99402, null, '2026-08-01', 'Full 30');
            for ($i = 0; $i < 30; $i++) {
                $this->createTask($full, 8, SeoProjectTask::STATUS_PENDING);
            }

            $room = $this->createMonthly(99403, null, '2026-08-01', 'Has room');
            $this->createTask($room, 9, SeoProjectTask::STATUS_PENDING);

            $options = app(SeoProjectTaskMoveService::class)->moveTargetOptions($source->fresh() ?? $source);
            $ids = array_map('intval', array_keys($options));

            self::assertNotContains((int) $full->id, $ids);
            self::assertContains((int) $room->id, $ids);
        } finally {
            $conn->rollBack();
        }
    }

    public function test_live_db_cross_writer_move_preserves_task_site_id(): void
    {
        $conn = SeoProject::query()->getConnection();
        $conn->beginTransaction();

        try {
            $source = $this->createMonthly(99301, null, '2026-08-01', 'Move src');
            $target = $this->createMonthly(99302, null, '2026-08-01', 'Move tgt other site items');
            $this->createTask($target, 8, SeoProjectTask::STATUS_PENDING);

            $task = $this->createTask($source, 7, SeoProjectTask::STATUS_REVIEWING, 88002);
            $task->forceFill([
                'planning_reviewed_at' => '2026-08-10 09:00:00',
                'planning_reviewed_by' => 99301,
            ])->save();

            $beforeSite = (int) $task->site_id;
            self::assertSame(7, $beforeSite);

            $result = app(SeoProjectTaskMoveService::class)->moveTasksToProject(
                $source,
                $target,
                [(int) $task->id],
            );

            self::assertSame(1, $result['moved']);
            $fresh = $task->fresh();
            self::assertNotNull($fresh);
            self::assertSame((int) $target->id, (int) $fresh->project_id);
            self::assertSame(7, (int) $fresh->site_id);
            self::assertSame(SeoProjectTask::STATUS_REVIEWING, (string) $fresh->status);
            self::assertSame(88002, (int) $fresh->article_id);
            self::assertNotNull($fresh->planning_reviewed_at);
            self::assertSame(99301, (int) $fresh->planning_reviewed_by);
        } finally {
            $conn->rollBack();
        }
    }

    public function test_live_empty_pending_project_with_runs_is_deletable(): void
    {
        $conn = SeoProject::query()->getConnection();
        $conn->beginTransaction();

        try {
            $project = $this->createMonthly(99501, null, '2026-08-01', 'Empty pending');
            $project->forceFill(['status' => SeoProject::STATUS_PENDING])->save();

            // Leftover run history must not block empty delete.
            \Omnichannel\Addons\ContentProjects\Models\SeoProjectRun::query()->create([
                'project_id' => (int) $project->id,
                'user_id' => 99501,
                'status' => 'completed',
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $svc = app(SeoProjectTaskMoveService::class);
            self::assertTrue($svc->hasStartedExecution($project->fresh() ?? $project));
            self::assertSame(0, $project->tasks()->active()->count());

            $result = $svc->deleteProject($project->fresh() ?? $project);
            self::assertTrue($result['deleted']);
            self::assertNull(SeoProject::query()->find($project->id));
        } finally {
            $conn->rollBack();
        }
    }

    public function test_live_project_1080_lists_all_aug_execution_projects(): void
    {
        $source = SeoProject::query()->find(1080);
        if (! $source instanceof SeoProject) {
            $this->markTestSkipped('seo_projects.id=1080 not present.');
        }

        $options = app(SeoProjectTaskMoveService::class)->moveTargetOptions($source);
        $ids = array_map('intval', array_keys($options));

        self::assertNotSame([], $ids);
        self::assertNotContains(1080, $ids);
        self::assertNotContains(81, $ids, 'Planning Draft must be absent');
        self::assertNotContains(21, $ids, 'Yến Huỳnh at 30 items must be absent');
        self::assertNotContains(25, $ids, 'Trang Nguyễn at 30 items must be absent');

        foreach ([26, 497, 498, 501] as $expectedId) {
            $peer = SeoProject::query()->find($expectedId);
            if (! $peer instanceof SeoProject) {
                continue;
            }
            if ($peer->archived_at !== null || (string) $peer->status === SeoProject::STATUS_DRAFT) {
                continue;
            }
            if ($peer->monthCarbon()->format('Y-m') !== '2026-08') {
                continue;
            }
            if ($peer->registeredTaskCount() >= 30) {
                continue;
            }
            self::assertContains($expectedId, $ids, "Aug execution project {$expectedId} with free slots must be listed");
        }
    }

    private function bindWriterMonthlyCapacity(int $capacity): void
    {
        $capacitySettings = ContentProjectWriterCapacitySettingsService::withDefaults();
        $mem = new \ReflectionProperty($capacitySettings, 'inMemorySettings');
        $mem->setAccessible(true);
        $mem->setValue($capacitySettings, [
            ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY => $capacity,
        ]);
        app()->instance(ContentProjectWriterCapacitySettingsService::class, $capacitySettings);
        app()->forgetInstance(ContentProjectWriterMonthlyCapacityService::class);
        app()->forgetInstance(WriterMonthlyCapacityGate::class);
        try {
            cache()->forget('content_writer_monthly_capacity_settings.v1');
        } catch (\Throwable) {
            // ignore
        }
    }

    private function createMonthly(int $userId, ?int $siteId, string $month, string $name): SeoProject
    {
        return SeoProject::query()->create([
            'name' => $name.' '.uniqid('', true),
            'user_id' => $userId,
            'site_id' => $siteId,
            'month' => $month,
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
    }

    private function createTask(
        SeoProject $project,
        int $siteId,
        string $status,
        ?int $articleId = null,
    ): SeoProjectTask {
        $seq = ++$this->seq;

        return SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'live-move-'.$seq,
            'keyword' => 'live-move-'.$seq,
            'status' => $status,
            'article_id' => $articleId,
            'target_date' => $project->monthCarbon()->format('Y-m-d'),
        ]);
    }

    private function restoreMysqlDatabasesFromDotEnv(string $root): void
    {
        $envFile = $root.DIRECTORY_SEPARATOR.'.env';
        if (! is_file($envFile)) {
            return;
        }

        $values = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $raw] = explode('=', $line, 2);
            $values[trim($key)] = trim($raw, " \t\"'");
        }

        $mysqlDb = (string) ($values['DB_DATABASE'] ?? '');
        if ($mysqlDb !== '' && $mysqlDb !== ':memory:') {
            Config::set('database.default', 'mysql');
            Config::set('database.connections.mysql.driver', 'mysql');
            Config::set('database.connections.mysql.database', $mysqlDb);
            if (isset($values['DB_HOST'])) {
                Config::set('database.connections.mysql.host', $values['DB_HOST']);
            }
            if (isset($values['DB_USERNAME'])) {
                Config::set('database.connections.mysql.username', $values['DB_USERNAME']);
            }
            if (array_key_exists('DB_PASSWORD', $values)) {
                Config::set('database.connections.mysql.password', $values['DB_PASSWORD']);
            }
            DB::purge('mysql');
        }

        if (isset($values['SEO_DB_DATABASE']) && $values['SEO_DB_DATABASE'] !== '') {
            Config::set('database.connections.omi_seo_ai.driver', 'mysql');
            Config::set('database.connections.omi_seo_ai.database', $values['SEO_DB_DATABASE']);
        } else {
            Config::set('database.connections.omi_seo_ai.driver', 'mysql');
            Config::set('database.connections.omi_seo_ai.database', 'omi_seo_ai');
        }
        if (isset($values['SEO_DB_HOST']) || isset($values['DB_HOST'])) {
            Config::set('database.connections.omi_seo_ai.host', $values['SEO_DB_HOST'] ?? $values['DB_HOST']);
        }
        if (isset($values['SEO_DB_USERNAME']) || isset($values['DB_USERNAME'])) {
            Config::set(
                'database.connections.omi_seo_ai.username',
                $values['SEO_DB_USERNAME'] ?? $values['DB_USERNAME'],
            );
        }
        if (array_key_exists('SEO_DB_PASSWORD', $values) || array_key_exists('DB_PASSWORD', $values)) {
            Config::set(
                'database.connections.omi_seo_ai.password',
                $values['SEO_DB_PASSWORD'] ?? $values['DB_PASSWORD'] ?? '',
            );
        }
        DB::purge('omi_seo_ai');
    }
}
