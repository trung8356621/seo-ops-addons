<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthBalanceEligibility;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthBalancePlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthBalanceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SitePlanningAndMonthBalanceConsistencyTest extends TestCase
{
    public function test_site_planning_aggregator_excludes_task_level_archived(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningActiveUnitAggregator::class))->getFileName(),
        );

        self::assertStringContainsString("whereNull('t.deleted_at')", $src);
        self::assertStringContainsString("whereNull('t.archived_at')", $src);
        self::assertStringContainsString('STATUS_CANCELLED', $src);
        self::assertStringContainsString('STATUS_ARCHIVED', $src);
    }

    public function test_pure_move_conserves_totals_across_all_modes_without_duplicates(): void
    {
        $planner = new ContentProjectMonthBalancePlanner;
        $movable = [];
        for ($id = 1; $id <= 5; $id++) {
            $movable[] = ['id' => $id, 'month' => '2026-09'];
        }
        for ($id = 6; $id <= 25; $id++) {
            $movable[] = ['id' => $id, 'month' => '2026-11'];
        }

        $service = new ContentProjectMonthBalanceService($planner);

        foreach ([
            ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER,
            ContentProjectMonthBalancePlanner::MODE_FILL_LATER,
            ContentProjectMonthBalancePlanner::MODE_EVEN,
        ] as $mode) {
            $plan = $planner->plan(['2026-09', '2026-11'], ['2026-09' => 0, '2026-11' => 0], $movable, $mode);
            $reconciliation = $service->validatePlanReconciliation($plan);

            self::assertTrue($reconciliation['reconciled'], "Mode {$mode} must reconcile");
            self::assertSame(25, $reconciliation['total_before']);
            self::assertSame(25, $reconciliation['total_after']);
            self::assertSame(array_sum($plan['current_by_month']), array_sum($plan['target_by_month']));

            $seenIds = [];
            foreach ($plan['moves'] as $move) {
                self::assertArrayNotHasKey($move['task_id'], $seenIds);
                $seenIds[$move['task_id']] = true;
                self::assertNotSame($move['from'], $move['to']);
            }
        }
    }

    public function test_reconciliation_rejects_disappearing_tasks_and_duplicates(): void
    {
        $service = new ContentProjectMonthBalanceService;

        $unbalanced = $service->validatePlanReconciliation([
            'months' => ['2026-09', '2026-11'],
            'current_by_month' => ['2026-09' => 5, '2026-11' => 20],
            'target_by_month' => ['2026-09' => 5, '2026-11' => 0],
            'moves' => [['task_id' => 1, 'from' => '2026-11', 'to' => '2026-09']],
            'move_count' => 1,
        ]);
        self::assertFalse($unbalanced['reconciled']);

        $duplicate = $service->validatePlanReconciliation([
            'months' => ['2026-09', '2026-11'],
            'current_by_month' => ['2026-09' => 5, '2026-11' => 20],
            'target_by_month' => ['2026-09' => 7, '2026-11' => 18],
            'moves' => [
                ['task_id' => 10, 'from' => '2026-11', 'to' => '2026-09'],
                ['task_id' => 10, 'from' => '2026-11', 'to' => '2026-09'],
            ],
            'move_count' => 2,
        ]);
        self::assertFalse($duplicate['reconciled']);

        $unresolvedDest = $service->validatePlanReconciliation([
            'months' => ['2026-09', '2026-11'],
            'current_by_month' => ['2026-09' => 5, '2026-11' => 20],
            'target_by_month' => ['2026-09' => 6, '2026-11' => 19],
            'moves' => [
                ['task_id' => 10, 'from' => '2026-11', 'to' => '2026-12'],
            ],
            'move_count' => 1,
        ]);
        self::assertFalse($unresolvedDest['reconciled']);
    }

    public function test_eligibility_matches_canonical_contract_for_generated_and_archived(): void
    {
        $eligibility = new ContentProjectMonthBalanceEligibility;

        $archivedGate = $eligibility->classifyFromFacts([
            'archived' => true,
            'raw_status' => SeoProjectTask::STATUS_ARCHIVED,
        ]);
        self::assertFalse($archivedGate['in_domain']);
        self::assertFalse($archivedGate['movable']);

        $projectArchivedGate = $eligibility->classifyFromFacts([
            'project_archived' => true,
            'raw_status' => SeoProjectTask::STATUS_PENDING,
        ]);
        self::assertFalse($projectArchivedGate['in_domain']);

        $completedGeneratedGate = $eligibility->classifyFromFacts([
            'archived' => false,
            'generator_done' => true,
            'published_at' => true,
            'raw_status' => SeoProjectTask::STATUS_COMPLETED,
        ]);
        self::assertTrue($completedGeneratedGate['in_domain']);
        self::assertTrue($completedGeneratedGate['movable']);
    }

    public function test_list_seo_projects_enforces_non_empty_fingerprint_and_reconciliation_ui(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );

        self::assertStringContainsString("if (\$fingerprint === '')", $src);
        self::assertStringContainsString('total_before', $src);
        self::assertStringContainsString('total_after', $src);
        self::assertStringContainsString('transfer_summary', $src);
    }

    public function test_monthly_workload_service_uses_canonical_planning_month_and_deduplicates_tasks(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('PlanningMonthBackfill::resolve', $src);
        self::assertStringContainsString("whereDate('t.planning_month', \$monthDate)", $src);
        self::assertStringContainsString('COALESCE(t.planning_month, p.month)', $src);
        self::assertStringContainsString('STATUS_ARCHIVED', $src);
        self::assertStringContainsString('$seenTaskIds[$taskId]', $src);
    }

    public function test_global_legacy_excluded_from_balance_and_projects_list_even_when_unarchived(): void
    {
        $eligibility = new ContentProjectMonthBalanceEligibility;
        $legacyProject = (new SeoProject)->setRawAttributes([
            'id' => 966,
            'status' => SeoProject::STATUS_COMPLETED,
            'archived_at' => null,
            'meta' => json_encode(['import_source' => 'seo_content_archive_items']),
        ]);
        $task = (new SeoProjectTask)->setRawAttributes([
            'archived_at' => null,
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);

        $gate = $eligibility->classify($task, $legacyProject);
        self::assertFalse($gate['in_domain']);
        self::assertFalse($gate['movable']);
        self::assertSame(ContentProjectMonthBalanceEligibility::REASON_GLOBAL_LEGACY, $gate['reason']);

        $resourceSrc = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        self::assertStringContainsString('ContentProjectGlobalLegacyArchive::excludeFromProjectAlias', $resourceSrc);
    }
}
