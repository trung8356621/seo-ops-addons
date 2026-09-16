<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use App\Services\Users\SeoOpsSystemUser;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\ContentProjects\Services\WriterMonthlyCapacityGate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Writer monthly capacity SSOT on execution-entry paths (not packing-only).
 */
final class WriterMonthlyCapacityGateClosureTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        if (! Schema::connection('omi_seo_ai')->hasTable('seo_projects')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
        ) {
            $this->markTestSkipped('seo_projects tables are not available.');
        }

        $this->bindWriterMonthlyCapacity(30);
    }

    public function test_gate_codes_are_stable(): void
    {
        self::assertSame('writer.capacity_exceeded', ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED);
        self::assertSame('writer.system_user_rejected', ContentProjectActionCodes::SYSTEM_USER_REJECTED);
    }

    public function test_assignment_paths_delegate_to_capacity_gate(): void
    {
        $keyword = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordProjectAssignmentService::class))->getFileName(),
        );
        $addItems = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/AddContentProjectItemsHandler.php',
        );
        $seoIssue = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo/src/Services/SeoIssueProjectTaskAssignmentService.php',
        );
        $sync = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService::class))->getFileName(),
        );
        $create = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/CreateContentProjectHandler.php',
        );

        foreach ([$keyword, $addItems, $seoIssue, $sync, $create] as $src) {
            self::assertStringContainsString('WriterMonthlyCapacityGate', $src);
        }
        self::assertStringContainsString('assertProjectCanAccept', $keyword);
        self::assertStringContainsString('WRITER_CAPACITY_EXCEEDED', $keyword);
    }

    public function test_user_at_29_accepts_one_more(): void
    {
        $project = $this->createMonthly(88101, 89101, '2026-09-01');
        $this->fillActiveTasks($project, 29);

        $result = app(KeywordProjectAssignmentService::class)->assignPhrases(
            ['cap-ok-phrase-'.(++$this->seq)],
            (int) $project->id,
            89101,
        );

        self::assertSame(1, $result['added']);
        self::assertSame(0, $result['overflow']);
        self::assertArrayNotHasKey('code', $result);
        self::assertSame(30, $project->fresh()?->registeredTaskCount());
    }

    public function test_user_at_30_rejects_assign(): void
    {
        $project = $this->createMonthly(88102, 89102, '2026-09-01');
        $this->fillActiveTasks($project, 30);

        $result = app(KeywordProjectAssignmentService::class)->assignPhrases(
            ['cap-full-phrase-'.(++$this->seq)],
            (int) $project->id,
            89102,
        );

        self::assertSame(0, $result['added']);
        self::assertSame(1, $result['overflow']);
        self::assertSame(ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED, $result['code'] ?? null);
        self::assertSame(30, $project->fresh()?->registeredTaskCount());
    }

    public function test_capacity_is_cross_project_for_same_writer_month(): void
    {
        $a = $this->createMonthly(88103, 89103, '2026-09-01', 'Cap A');
        $b = $this->createMonthly(88103, 89103, '2026-09-01', 'Cap B');
        $c = $this->createMonthly(88103, 89103, '2026-09-01', 'Cap C');
        $this->fillActiveTasks($a, 20);
        $this->fillActiveTasks($b, 10);

        $result = app(KeywordProjectAssignmentService::class)->assignPhrases(
            ['cross-project-reject-'.(++$this->seq)],
            (int) $c->id,
            89103,
        );

        self::assertSame(0, $result['added']);
        self::assertSame(ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED, $result['code'] ?? null);
        self::assertSame(0, $c->fresh()?->registeredTaskCount());
    }

    public function test_draft_items_do_not_consume_capacity(): void
    {
        $draft = SeoProject::query()->create([
            'name' => 'Draft cap '.$this->seq,
            'user_id' => 88104,
            'site_id' => null,
            'month' => SeoProject::draftCompatibilityMonth(),
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
        $this->fillActiveTasks($draft, 40);

        $exec = $this->createMonthly(88104, 89104, '2026-09-01');
        $gate = app(WriterMonthlyCapacityGate::class);
        $ok = $gate->assertProjectCanAccept($exec, 1);
        self::assertTrue($ok['ok'] ?? false);

        $draftGate = $gate->assertProjectCanAccept($draft, 100);
        self::assertTrue($draftGate['ok'] ?? false);
    }

    public function test_cancelled_tasks_do_not_count(): void
    {
        $project = $this->createMonthly(88105, 89105, '2026-09-01');
        $this->fillActiveTasks($project, 29);
        SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => 89105,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'cancelled-slot-'.(++$this->seq),
            'keyword' => 'cancelled-slot-'.$this->seq,
            'status' => SeoProjectTask::STATUS_CANCELLED,
            'target_date' => '2026-09-15',
        ]);

        $result = app(KeywordProjectAssignmentService::class)->assignPhrases(
            ['after-cancel-'.(++$this->seq)],
            (int) $project->id,
            89105,
        );
        self::assertSame(1, $result['added']);
    }

    public function test_system_user_rejected(): void
    {
        $systemId = (int) (SeoOpsSystemUser::id() ?: 1);
        $project = $this->createMonthly($systemId, 89106, '2026-09-01');

        $result = app(WriterMonthlyCapacityGate::class)->assertProjectCanAccept($project, 1);
        self::assertFalse($result['ok'] ?? true);
        self::assertSame(ContentProjectActionCodes::SYSTEM_USER_REJECTED, $result['code'] ?? null);
    }

    public function test_bulk_assign_rejects_entire_batch_when_over_capacity(): void
    {
        $project = $this->createMonthly(88107, 89107, '2026-09-01');
        $this->fillActiveTasks($project, 28);

        $phrases = [];
        for ($i = 0; $i < 5; $i++) {
            $phrases[] = 'bulk-over-'.(++$this->seq);
        }

        $result = app(KeywordProjectAssignmentService::class)->assignPhrases(
            $phrases,
            (int) $project->id,
            89107,
        );

        self::assertSame(0, $result['added']);
        self::assertSame(5, $result['overflow']);
        self::assertSame(ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED, $result['code'] ?? null);
        self::assertSame(28, $project->fresh()?->registeredTaskCount());
    }

    private function bindWriterMonthlyCapacity(int $capacity): void
    {
        $capacitySettings = ContentProjectWriterCapacitySettingsService::withDefaults();
        $mem = new \ReflectionProperty($capacitySettings, 'inMemorySettings');
        $mem->setAccessible(true);
        $mem->setValue($capacitySettings, [
            ContentProjectWriterCapacitySettingsService::KEY_DEFAULT_CAPACITY => $capacity,
        ]);
        $this->app->instance(ContentProjectWriterCapacitySettingsService::class, $capacitySettings);
        $this->app->forgetInstance(ContentProjectWriterMonthlyCapacityService::class);
        $this->app->forgetInstance(WriterMonthlyCapacityGate::class);
        try {
            cache()->forget('content_writer_monthly_capacity_settings.v1');
        } catch (\Throwable) {
            // ignore
        }
    }

    private function createMonthly(int $userId, int $siteId, string $month, string $name = 'Cap proj'): SeoProject
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

    private function fillActiveTasks(SeoProject $project, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            SeoProjectTask::query()->create([
                'project_id' => (int) $project->id,
                'site_id' => (int) ($project->site_id ?? 0) ?: null,
                'type' => SeoProjectTask::TYPE_CREATE,
                'source_content' => 'fill-'.(++$this->seq),
                'keyword' => 'fill-'.$this->seq,
                'status' => SeoProjectTask::STATUS_PENDING,
                'target_date' => '2026-09-01',
            ]);
        }
        $project->syncTotalTasksCounter();
    }
}
