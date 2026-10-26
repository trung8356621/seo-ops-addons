<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ChangeContentProjectWriterCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ChangeContentProjectWriterService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Services\WriterMonthlyCapacityGate;
use Tests\TestCase;

final class ChangeContentProjectWriterIntegrationTest extends TestCase
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
        $this->app->forgetInstance(ContentProjectCommandBus::class);
        $this->app->singleton(ContentProjectCommandBus::class, function ($app): ContentProjectCommandBus {
            $bus = new ContentProjectCommandBus(
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectIdempotencyStore::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessAuditor::class),
                $app->make(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectOperationLogger::class),
            );
            $app->make(ContentProjectCommandBusRegistrar::class)->register($bus);

            return $bus;
        });
    }

    public function test_change_writer_keeps_project_id_tasks_and_statuses(): void
    {
        $from = 87101;
        $to = 87102;
        $project = $this->createMonthly($from, '2026-10-01', 'Change writer keep');
        $task = $this->addTask($project, [
            'status' => SeoProjectTask::STATUS_COMPLETED,
            'keyword' => 'keep-kw-'.(++$this->seq),
        ]);
        $article = null;
        $wpAuthorBefore = null;
        if (Schema::connection('omi_seo_ai')->hasTable('articles')) {
            $article = $this->createArticle($from, 'Keep article '.$this->seq);
            if (Schema::connection('omi_seo_ai')->hasColumn('articles', 'wp_author_id')) {
                $article->forceFill(['wp_author_id' => 99])->save();
                $wpAuthorBefore = 99;
            } elseif (Schema::connection('omi_seo_ai')->hasColumn('articles', 'author_name')) {
                $article->forceFill(['author_name' => 'WP Author Keep'])->save();
                $wpAuthorBefore = 'WP Author Keep';
            }
            $task->article_id = (int) $article->id;
            $task->save();
        }

        $projectId = (int) $project->id;
        $taskStatus = (string) $task->status;
        $taskCountBefore = $project->registeredTaskCount();

        $result = app(ChangeContentProjectWriterService::class)->changeWriter($project, $to);

        self::assertTrue($result['ok'] ?? false, (string) ($result['reason'] ?? 'failed'));
        self::assertSame($projectId, (int) ($result['project_id'] ?? 0));

        $fresh = SeoProject::query()->findOrFail($projectId);
        self::assertSame($to, (int) $fresh->user_id);
        self::assertSame($projectId, (int) $fresh->id);
        self::assertSame($taskCountBefore, $fresh->registeredTaskCount());
        self::assertNull($fresh->archived_at);

        $freshTask = SeoProjectTask::query()->findOrFail((int) $task->id);
        self::assertSame($projectId, (int) $freshTask->project_id);
        self::assertSame($taskStatus, (string) $freshTask->status);
        self::assertSame((string) $task->keyword, (string) $freshTask->keyword);

        if ($article instanceof SeoArticle) {
            $freshArticle = SeoArticle::query()->findOrFail((int) $article->id);
            self::assertSame($to, (int) $freshArticle->user_id);
            if ($wpAuthorBefore !== null && Schema::connection('omi_seo_ai')->hasColumn('articles', 'wp_author_id')) {
                self::assertSame(99, (int) $freshArticle->wp_author_id);
            }
            if (is_string($wpAuthorBefore) && Schema::connection('omi_seo_ai')->hasColumn('articles', 'author_name')) {
                self::assertSame('WP Author Keep', (string) $freshArticle->author_name);
            }
        }
    }

    public function test_rejects_same_writer(): void
    {
        $project = $this->createMonthly(87201, '2026-10-01');
        $result = app(ChangeContentProjectWriterService::class)->changeWriter($project, 87201);

        self::assertFalse($result['ok'] ?? true);
        self::assertSame(ContentProjectActionCodes::VALIDATION_FAILED, $result['code'] ?? null);
        self::assertSame(87201, (int) $project->fresh()?->user_id);
    }

    public function test_rejects_archived_project(): void
    {
        $project = $this->createMonthly(87301, '2026-10-01');
        $project->archived_at = now();
        $project->save();

        $result = app(ChangeContentProjectWriterService::class)->changeWriter($project, 87302);

        self::assertFalse($result['ok'] ?? true);
        self::assertSame(ContentProjectActionCodes::LIFECYCLE_INVALID, $result['code'] ?? null);
    }

    public function test_rejects_when_ai_running(): void
    {
        $project = $this->createMonthly(87401, '2026-10-01');
        SeoProjectRun::query()->create([
            'project_id' => (int) $project->id,
            'user_id' => 87401,
            'mode' => SeoProjectRun::MODE_FULL,
            'status' => SeoProjectRun::STATUS_RUNNING,
            'total' => 1,
            'succeeded' => 0,
            'failed' => 0,
            'started_at' => now(),
        ]);

        $result = app(ChangeContentProjectWriterService::class)->changeWriter($project, 87402);

        self::assertFalse($result['ok'] ?? true);
        self::assertSame(ContentProjectActionCodes::LIFECYCLE_INVALID, $result['code'] ?? null);
        self::assertSame(87401, (int) $project->fresh()?->user_id);
    }

    public function test_rejects_when_publish_queue_processing(): void
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $this->markTestSkipped('publish_queue_status column missing.');
        }

        $project = $this->createMonthly(87501, '2026-10-01');
        $this->addTask($project, [
            'status' => SeoProjectTask::STATUS_COMPLETED,
            'publish_queue_status' => ContentProjectPublishQueueStatus::Processing->value,
        ]);

        $result = app(ChangeContentProjectWriterService::class)->changeWriter($project, 87502);

        self::assertFalse($result['ok'] ?? true);
        self::assertSame(ContentProjectActionCodes::LIFECYCLE_INVALID, $result['code'] ?? null);
    }

    public function test_rejects_when_target_writer_capacity_exceeded(): void
    {
        $this->bindWriterMonthlyCapacity(5);
        $targetBusy = $this->createMonthly(87602, '2026-10-01', 'Busy B');
        $this->fillActiveTasks($targetBusy, 5);

        $project = $this->createMonthly(87601, '2026-10-01', 'Move A');
        $this->fillActiveTasks($project, 2);

        $result = app(ChangeContentProjectWriterService::class)->changeWriter($project, 87602);

        self::assertFalse($result['ok'] ?? true);
        self::assertSame(ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED, $result['code'] ?? null);
        self::assertSame(87601, (int) $project->fresh()?->user_id);
    }

    public function test_allows_second_project_same_month_when_capacity_ok(): void
    {
        // Month uniqueness is retired — Writer B may already have a project.
        $this->createMonthly(87702, '2026-10-01', 'Existing B');
        $project = $this->createMonthly(87701, '2026-10-01', 'Move to B');
        $this->addTask($project, ['keyword' => 'cap-ok-'.(++$this->seq)]);

        $result = app(ChangeContentProjectWriterService::class)->changeWriter($project, 87702);

        self::assertTrue($result['ok'] ?? false, (string) ($result['reason'] ?? 'failed'));
        self::assertSame(87702, (int) $project->fresh()?->user_id);
    }

    public function test_command_bus_success_and_forbidden_without_auth(): void
    {
        $project = $this->createMonthly(87801, '2026-10-01');
        $this->addTask($project, ['keyword' => 'bus-'.(++$this->seq)]);

        $forbidden = app(ContentProjectCommandBus::class)->dispatch(
            new ChangeContentProjectWriterCommand((int) $project->id, 87802),
            ActorContext::user(null, null),
        );
        self::assertFalse($forbidden->success);
        self::assertSame(ContentProjectActionCodes::FORBIDDEN, $forbidden->code);
        self::assertSame(87801, (int) $project->fresh()?->user_id);

        $actor = User::query()->where('role', 'owner')->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();
        if (! $actor instanceof User) {
            $this->markTestSkipped('No user available for authorized change-writer path.');
        }
        $this->actingAs($actor);

        $ok = app(ContentProjectCommandBus::class)->dispatch(
            new ChangeContentProjectWriterCommand((int) $project->id, 87802),
            ActorContext::user((int) $actor->id, null),
        );

        self::assertTrue($ok->success, $ok->message);
        self::assertSame(ContentProjectActionCodes::PROJECT_WRITER_CHANGED, $ok->code);
        self::assertSame(87802, (int) $project->fresh()?->user_id);
        self::assertSame((int) $project->id, (int) ($ok->projectId ?? 0));
    }

    public function test_rejects_invalid_writer_id(): void
    {
        $project = $this->createMonthly(87901, '2026-10-01');
        $result = app(ChangeContentProjectWriterService::class)->changeWriter($project, 0);

        self::assertFalse($result['ok'] ?? true);
        self::assertSame(ContentProjectActionCodes::SYSTEM_USER_REJECTED, $result['code'] ?? null);
    }

    public function test_writer_filter_sees_new_assignment(): void
    {
        $project = $this->createMonthly(88001, '2026-10-01', 'Filter reflect');
        app(ChangeContentProjectWriterService::class)->changeWriter($project, 88002);

        $forOld = SeoProject::query()
            ->where('user_id', 88001)
            ->whereKey((int) $project->id)
            ->exists();
        $forNew = SeoProject::query()
            ->where('user_id', 88002)
            ->whereKey((int) $project->id)
            ->exists();

        self::assertFalse($forOld);
        self::assertTrue($forNew);
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
        $this->app->forgetInstance(ChangeContentProjectWriterService::class);
        try {
            cache()->forget('content_writer_monthly_capacity_settings.v1');
        } catch (\Throwable) {
            // ignore
        }
    }

    private function createMonthly(int $userId, string $month, string $name = 'CW proj'): SeoProject
    {
        return SeoProject::query()->create([
            'name' => $name.' '.uniqid('', true),
            'user_id' => $userId,
            'site_id' => null,
            'month' => $month,
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function addTask(SeoProject $project, array $attrs = []): SeoProjectTask
    {
        $task = SeoProjectTask::query()->create(array_merge([
            'project_id' => (int) $project->id,
            'site_id' => null,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'cw-'.(++$this->seq),
            'keyword' => 'cw-kw-'.$this->seq,
            'status' => SeoProjectTask::STATUS_PENDING,
            'target_date' => '2026-10-01',
        ], $attrs));
        $project->syncTotalTasksCounter();

        return $task;
    }

    private function fillActiveTasks(SeoProject $project, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->addTask($project);
        }
    }

    private function createArticle(int $userId, string $title): SeoArticle
    {
        $slug = 'cw-'.strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $title)).'-'.(++$this->seq);

        return SeoArticle::query()->create([
            'site_id' => 99001,
            'user_id' => $userId,
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>'.$title.'</p>',
            'status' => 'published',
        ]);
    }
}
