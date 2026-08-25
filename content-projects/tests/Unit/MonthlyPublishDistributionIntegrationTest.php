<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAutoScheduleService;
use Tests\TestCase;

/**
 * Site-level anchors + monthly_even (requires SEO_TEST_USE_MYSQL=true + omi_seo_ai).
 */
final class MonthlyPublishDistributionIntegrationTest extends TestCase
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
    }

    public function test_existing_site_schedule_is_anchor_for_second_project(): void
    {
        $tz = SystemDateTime::timezone();
        // Remaining window = 1 day so the new slot must share the anchor day.
        Carbon::setTestNow(Carbon::parse('2026-09-30 08:00:00', $tz));

        try {
            $siteId = 9_300_100 + $this->seq++;
            $projectA = $this->createProject($siteId, '2026-09-01');
            $projectB = $this->createProject($siteId, '2026-09-01');

            $anchorTask = $this->createQueuedTask($projectA, $siteId);
            $taskB = $this->createQueuedTask($projectB, $siteId);

            $service = app(ContentProjectAutoScheduleService::class);
            $options = [
                'mode' => 'monthly_even',
                'min_spacing_minutes' => 5,
                'day_start' => '09:00',
                'day_end' => '17:00',
                'allow_reschedule' => false,
            ];

            $first = $service->schedule($projectA, [(int) $anchorTask->id], $options);
            self::assertSame(1, (int) $first['scheduled']);

            $anchorAt = $anchorTask->fresh()?->scheduled_publish_at;
            self::assertInstanceOf(Carbon::class, $anchorAt);
            $anchorLocal = $anchorAt->copy()->timezone($tz);

            $preview = $service->preview($projectB, [(int) $taskB->id], $options);

            self::assertEmpty($preview['blocked'] ?? null);
            self::assertCount(1, $preview['slots']);
            self::assertGreaterThanOrEqual(1, (int) ($preview['distribution_meta']['existing_anchor_count'] ?? 0));

            $slotLocal = Carbon::parse((string) $preview['slots'][0])->timezone($tz);
            self::assertSame($anchorLocal->format('Y-m-d'), $slotLocal->format('Y-m-d'));
            self::assertNotSame(
                $anchorAt->copy()->utc()->toIso8601String(),
                Carbon::parse((string) $preview['slots'][0])->utc()->toIso8601String(),
            );
            self::assertGreaterThanOrEqual(
                5,
                abs((int) $anchorLocal->diffInMinutes($slotLocal, false)),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_monthly_even_preview_is_deterministic(): void
    {
        $tz = SystemDateTime::timezone();
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', $tz));

        try {
            $siteId = 9_300_200 + $this->seq++;
            $project = $this->createProject($siteId, '2026-09-01');

            for ($i = 0; $i < 3; $i++) {
                $this->createQueuedTask($project, $siteId);
            }

            $service = app(ContentProjectAutoScheduleService::class);
            $options = [
                'mode' => 'monthly_even',
                'min_spacing_minutes' => 5,
                'day_start' => '09:00',
                'day_end' => '17:00',
            ];

            $a = $service->preview($project, [], $options);
            $b = $service->preview($project, [], $options);

            self::assertSame($a['slots'], $b['slots']);
            self::assertSame('monthly_even', $a['distribution_meta']['mode'] ?? null);
            self::assertCount(3, $a['slots']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_second_auto_schedule_preserves_first_wave_and_fills_gaps(): void
    {
        $tz = SystemDateTime::timezone();
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', $tz));

        try {
            $siteId = 9_300_300 + $this->seq++;
            $project = $this->createProject($siteId, '2026-09-01');
            $service = app(ContentProjectAutoScheduleService::class);
            $options = [
                'mode' => 'monthly_even',
                'min_spacing_minutes' => 5,
                'day_start' => '09:00',
                'day_end' => '17:00',
                'allow_reschedule' => false,
            ];

            $firstWave = [];
            for ($i = 0; $i < 10; $i++) {
                $firstWave[] = $this->createQueuedTask($project, $siteId);
            }

            $result1 = $service->schedule($project, [], $options);
            self::assertSame(10, (int) $result1['scheduled']);

            $firstTimes = [];
            foreach ($firstWave as $task) {
                $fresh = $task->fresh();
                self::assertNotNull($fresh?->scheduled_publish_at);
                $firstTimes[(int) $task->id] = (string) $fresh->scheduled_publish_at;
            }

            $secondWave = [];
            for ($i = 0; $i < 20; $i++) {
                $secondWave[] = $this->createQueuedTask($project, $siteId);
            }

            $result2 = $service->schedule($project, [], $options);
            self::assertSame(20, (int) $result2['scheduled']);

            foreach ($firstWave as $task) {
                self::assertSame(
                    $firstTimes[(int) $task->id],
                    (string) $task->fresh()?->scheduled_publish_at,
                );
            }

            foreach ($secondWave as $task) {
                self::assertNotNull($task->fresh()?->scheduled_publish_at);
            }

            $result3 = $service->schedule($project, [], $options);
            self::assertSame(0, (int) $result3['scheduled']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_different_sites_may_share_exact_timestamp(): void
    {
        $tz = SystemDateTime::timezone();
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', $tz));

        try {
            $siteA = 9_300_400 + $this->seq++;
            $siteB = 9_300_500 + $this->seq++;
            $projectA = $this->createProject($siteA, '2026-09-01');
            $projectB = $this->createProject($siteB, '2026-09-01');

            $this->createQueuedTask($projectA, $siteA);
            $this->createQueuedTask($projectB, $siteB);

            $service = app(ContentProjectAutoScheduleService::class);
            $options = [
                'mode' => 'monthly_even',
                'min_spacing_minutes' => 5,
                'day_start' => '09:00',
                'day_end' => '17:00',
            ];

            $a = $service->preview($projectA, [], $options);
            $b = $service->preview($projectB, [], $options);

            self::assertSame($a['slots'][0] ?? null, $b['slots'][0] ?? null);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createProject(int $siteId, string $month): SeoProject
    {
        return SeoProject::query()->create([
            'name' => 'Monthly Even Test '.uniqid('', true),
            'site_id' => $siteId,
            'user_id' => 1,
            'month' => $month,
            'status' => SeoProject::STATUS_APPROVED,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
    }

    private function createQueuedTask(SeoProject $project, int $siteId): SeoProjectTask
    {
        $this->seq++;
        $token = 'me-'.uniqid('', true);
        $articleId = 9_200_000 + (($this->seq * 97) % 90_000) + ($siteId % 1000);

        $attrs = [
            'project_id' => (int) $project->id,
            'site_id' => $siteId,
            'article_id' => $articleId,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => $token,
            'keyword' => $token,
            'title' => $token,
            'status' => SeoProjectTask::STATUS_COMPLETED,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
            'publish_queue_status' => 'none',
            'target_date' => $project->monthCarbon()->format('Y-m-d'),
        ];

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at')) {
            $attrs['publishing_queued_at'] = now();
        }

        return SeoProjectTask::query()->create($attrs);
    }
}
