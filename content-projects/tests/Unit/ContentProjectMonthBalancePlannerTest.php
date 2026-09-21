<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthBalanceEligibility;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthBalancePlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\MoveContentProjectToNextMonthService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectMonthBalancePlannerTest extends TestCase
{
    private ContentProjectMonthBalancePlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new ContentProjectMonthBalancePlanner;
    }

    /**
     * @return list<array{id: int, month: string}>
     */
    private function movableRange(int $fromId, int $toId, string $month): array
    {
        $rows = [];
        for ($i = $fromId; $i <= $toId; $i++) {
            $rows[] = ['id' => $i, 'month' => $month];
        }

        return $rows;
    }

    public function test_default_mode_is_fill_earlier(): void
    {
        self::assertSame(
            ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER,
            ContentProjectMonthBalancePlanner::DEFAULT_MODE,
        );
    }

    public function test_fill_earlier_basic_aug_oct(): void
    {
        $movable = array_merge(
            $this->movableRange(1, 20, '2026-08'),
            $this->movableRange(21, 25, '2026-10'),
        );

        $plan = $this->planner->plan(
            ['2026-08', '2026-10'],
            ['2026-08' => 0, '2026-10' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER,
        );

        self::assertSame(25, $plan['target_by_month']['2026-08']);
        self::assertSame(0, $plan['target_by_month']['2026-10']);
        self::assertSame(5, $plan['move_count']);
        self::assertSame(ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER, $plan['mode']);
    }

    public function test_fill_later_basic_aug_oct(): void
    {
        $movable = array_merge(
            $this->movableRange(1, 20, '2026-08'),
            $this->movableRange(21, 25, '2026-10'),
        );

        $plan = $this->planner->plan(
            ['2026-08', '2026-10'],
            ['2026-08' => 0, '2026-10' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_FILL_LATER,
        );

        self::assertSame(0, $plan['target_by_month']['2026-08']);
        self::assertSame(25, $plan['target_by_month']['2026-10']);
        self::assertSame(20, $plan['move_count']);
    }

    public function test_three_months_fill_earlier(): void
    {
        $movable = array_merge(
            $this->movableRange(1, 10, '2026-07'),
            $this->movableRange(11, 30, '2026-08'),
            $this->movableRange(31, 60, '2026-09'),
        );

        $plan = $this->planner->plan(
            ['2026-07', '2026-08', '2026-09'],
            ['2026-07' => 0, '2026-08' => 0, '2026-09' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER,
        );

        self::assertSame(60, $plan['target_by_month']['2026-07']);
        self::assertSame(0, $plan['target_by_month']['2026-08']);
        self::assertSame(0, $plan['target_by_month']['2026-09']);
        self::assertSame(50, $plan['move_count']);
    }

    public function test_already_directional_fill_earlier_is_noop(): void
    {
        $movable = $this->movableRange(1, 25, '2026-08');

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 0, '2026-09' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER,
        );

        self::assertSame(25, $plan['target_by_month']['2026-08']);
        self::assertSame(0, $plan['target_by_month']['2026-09']);
        self::assertSame(0, $plan['move_count']);
    }

    public function test_fill_earlier_with_generated_excluded_pool(): void
    {
        // Aug: 30 generated excluded from planner input; Sep: 61 eligible only.
        $movable = $this->movableRange(1, 61, '2026-09');

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 0, '2026-09' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER,
        );

        self::assertSame(61, array_sum($plan['target_by_month']));
        self::assertSame(61, $plan['target_by_month']['2026-08']);
        self::assertSame(0, $plan['target_by_month']['2026-09']);
        self::assertSame(61, $plan['move_count']);
        self::assertNotSame(91, array_sum($plan['target_by_month']));
    }

    public function test_fill_earlier_keeps_fixed_non_generated_in_place(): void
    {
        $movable = array_merge(
            $this->movableRange(1, 2, '2026-08'),
            $this->movableRange(3, 12, '2026-09'),
        );

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 3, '2026-09' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER,
        );

        self::assertSame(3, $plan['fixed_by_month']['2026-08']);
        self::assertSame(15, $plan['target_by_month']['2026-08']); // 3 fixed + 12 movable
        self::assertSame(0, $plan['target_by_month']['2026-09']);
        self::assertSame(12, $plan['target_movable_by_month']['2026-08']);
        self::assertSame(10, $plan['move_count']); // 10 from Sep; 2 already in Aug stay
    }

    public function test_mode_changes_fingerprint(): void
    {
        $movable = array_merge(
            $this->movableRange(1, 20, '2026-08'),
            $this->movableRange(21, 25, '2026-10'),
        );
        $fixed = ['2026-08' => 0, '2026-10' => 0];
        $months = ['2026-08', '2026-10'];

        $earlier = $this->planner->plan($months, $fixed, $movable, ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER);
        $later = $this->planner->plan($months, $fixed, $movable, ContentProjectMonthBalancePlanner::MODE_FILL_LATER);
        $even = $this->planner->plan($months, $fixed, $movable, ContentProjectMonthBalancePlanner::MODE_EVEN);

        self::assertNotSame($earlier['fingerprint'], $later['fingerprint']);
        self::assertNotSame($earlier['fingerprint'], $even['fingerprint']);
        self::assertNotSame($later['fingerprint'], $even['fingerprint']);
        self::assertSame(25, $earlier['target_by_month']['2026-08']);
        self::assertSame(25, $later['target_by_month']['2026-10']);
    }

    public function test_default_plan_uses_fill_earlier_not_even(): void
    {
        $movable = array_merge(
            $this->movableRange(1, 20, '2026-08'),
            $this->movableRange(21, 25, '2026-10'),
        );

        $plan = $this->planner->plan(
            ['2026-08', '2026-10'],
            ['2026-08' => 0, '2026-10' => 0],
            $movable,
        );

        self::assertSame(ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER, $plan['mode']);
        self::assertSame(25, $plan['target_by_month']['2026-08']);
        self::assertSame(0, $plan['target_by_month']['2026-10']);
        // Even would be ~13/12 — must not happen by default.
        self::assertNotSame(13, $plan['target_by_month']['2026-08']);
    }

    public function test_even_mode_still_available(): void
    {
        $movable = $this->movableRange(1, 61, '2026-09');

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 0, '2026-09' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_EVEN,
        );

        self::assertSame(30, $plan['target_by_month']['2026-08']);
        self::assertSame(31, $plan['target_by_month']['2026-09']);
        self::assertSame(30, $plan['move_count']);
    }

    public function test_even_fixed_plus_movable_balances_final_load(): void
    {
        $movable = array_merge(
            $this->movableRange(1, 3, '2026-08'),
            $this->movableRange(4, 44, '2026-09'),
        );

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 17, '2026-09' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_EVEN,
        );

        self::assertSame(61, array_sum($plan['target_by_month']));
        self::assertSame(30, $plan['target_by_month']['2026-08']);
        self::assertSame(31, $plan['target_by_month']['2026-09']);
    }

    public function test_even_over_ideal_fixed_never_forces_below_fixed(): void
    {
        $movable = $this->movableRange(1, 10, '2026-09');

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 35, '2026-09' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_EVEN,
        );

        self::assertGreaterThanOrEqual(35, $plan['target_by_month']['2026-08']);
        self::assertSame(35, $plan['fixed_by_month']['2026-08']);
        self::assertSame(0, $plan['target_movable_by_month']['2026-08']);
        self::assertSame(10, $plan['target_movable_by_month']['2026-09']);
    }

    public function test_deterministic_repeated_runs(): void
    {
        $movable = $this->movableRange(1, 61, '2026-09');

        $a = $this->planner->plan(['2026-08', '2026-09'], ['2026-08' => 0, '2026-09' => 0], $movable);
        $b = $this->planner->plan(['2026-08', '2026-09'], ['2026-08' => 0, '2026-09' => 0], $movable);

        self::assertSame($a['fingerprint'], $b['fingerprint']);
        self::assertSame($a['allocation'], $b['allocation']);
        self::assertSame($a['moves'], $b['moves']);
    }

    public function test_selected_months_only_in_allocation_keys(): void
    {
        $movable = [
            ['id' => 1, 'month' => '2026-08'],
            ['id' => 2, 'month' => '2026-09'],
            ['id' => 3, 'month' => '2026-10'],
        ];

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 0, '2026-09' => 0],
            $movable,
            ContentProjectMonthBalancePlanner::MODE_FILL_EARLIER,
        );

        self::assertArrayNotHasKey(3, $plan['allocation']);
        self::assertSame(2, array_sum($plan['target_by_month']));
    }

    public function test_eligibility_published_and_scheduled_are_fixed(): void
    {
        $eligibility = new ContentProjectMonthBalanceEligibility;

        self::assertTrue($eligibility->classifyFromFacts([
            'published_at' => true,
        ])['fixed']);

        self::assertTrue($eligibility->classifyFromFacts([
            'queue' => 'waiting',
        ])['fixed']);

        self::assertTrue($eligibility->classifyFromFacts([
            'lifecycle' => 'waiting_publish',
        ])['fixed']);

        self::assertTrue($eligibility->classifyFromFacts([
            'publish_state' => 'scheduled',
        ])['fixed']);

        self::assertTrue($eligibility->classifyFromFacts([
            'raw_status' => 'pending',
            'safety_movable' => true,
            'project_ok' => true,
        ])['movable']);
    }

    public function test_generated_excluded_from_balance_domain_not_fixed_load(): void
    {
        $eligibility = new ContentProjectMonthBalanceEligibility;

        $generated = $eligibility->classifyFromFacts([
            'generator_done' => true,
            'queue' => 'none',
            'raw_status' => 'completed',
            'safety_movable' => true,
            'project_ok' => true,
        ]);
        self::assertFalse($generated['in_domain']);
        self::assertFalse($generated['movable']);
        self::assertFalse($generated['fixed']);
        self::assertSame(ContentProjectMonthBalanceEligibility::REASON_GENERATED, $generated['reason']);

        $generatedBare = $eligibility->classifyFromFacts([
            'generator_done' => true,
            'published_at' => false,
            'queue' => 'none',
            'lifecycle' => 'review',
            'publish_state' => 'none',
            'safety_movable' => true,
            'project_ok' => true,
        ]);
        self::assertFalse($generatedBare['in_domain']);
        self::assertFalse($generatedBare['fixed']);
    }

    public function test_balance_service_passes_mode_and_keeps_domain_packing(): void
    {
        $service = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthBalanceService::class,
            ))->getFileName(),
        );
        $eligibility = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthBalanceEligibility::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectGeneratorDoneClassifier', $eligibility);
        self::assertStringContainsString('REASON_GENERATED', $eligibility);
        self::assertStringContainsString('in_domain', $eligibility);
        self::assertStringContainsString('OUTSIDE the Balance domain', $eligibility);

        self::assertStringContainsString("! (\$gate['in_domain']", $service);
        self::assertStringContainsString('excluded_total', $service);
        self::assertStringContainsString('planPackForBalanceMonth', $service);
        self::assertStringContainsString('DEFAULT_MODE', $service);
        self::assertStringContainsString('normalizeMode', $service);
        self::assertDoesNotMatchRegularExpression('/\$packing->planPack\(/', $service);
    }

    public function test_month_context_nearby_and_shift(): void
    {
        self::assertSame(
            ['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'],
            ContentProjectMonthContext::nearbyMonths('2026-09', 2),
        );
        self::assertSame('2026-08', ContentProjectMonthContext::shift('2026-09', -1));
        self::assertSame('Sep', ContentProjectMonthContext::shortLabel('2026-09'));
    }

    public function test_move_next_month_stamps_planning_month(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(MoveContentProjectToNextMonthService::class))->getFileName(),
        );
        self::assertStringContainsString("'planning_month'", $src);
        self::assertStringContainsString('hasColumn(\'seo_project_tasks\', \'planning_month\')', $src);
    }

    public function test_list_page_month_nav_contract_and_balance_action(): void
    {
        $list = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects::class))->getFileName(),
        );
        self::assertStringContainsString('balance_months', $list);
        self::assertStringContainsString('ContentProjectMonthBalanceService', $list);
        self::assertStringContainsString('ContentProjectMonthBalancePlanner::DEFAULT_MODE', $list);
        self::assertStringContainsString('MODE_FILL_EARLIER', $list);
        self::assertStringContainsString('MODE_FILL_LATER', $list);
        self::assertStringContainsString('MODE_EVEN', $list);
        self::assertStringContainsString("ToggleButtons::make('mode')", $list);
        self::assertStringContainsString('balance_months_mode_earlier', $list);
        self::assertStringContainsString('compact_success_items', $list);
        self::assertStringContainsString('SitePlanningReadModel', $list);
        self::assertStringContainsString('getMonthlyPlanningMatrix', $list);
        self::assertStringContainsString('overviewForProjectsList', $list);
        self::assertStringNotContainsString('->overview(null, $this->planningMonth', $list);
        self::assertStringContainsString('balance_months_col_before', $list);
        self::assertStringContainsString('balance_months_stat_will_move', $list);
        self::assertStringContainsString('balance_months_stat_fixed_stay', $list);
        self::assertStringContainsString('space-y-4', $list);
        self::assertStringContainsString('px-3 py-2.5', $list);
        self::assertStringNotContainsString('balance_months_stat_fixed_changed', $list);
        self::assertStringNotContainsString('balance_months_col_fixed', $list);
        self::assertStringNotContainsString('balance_months_col_movable', $list);
        self::assertStringNotContainsString('balance_months_col_current', $list);

        $service = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthBalanceService::class))->getFileName(),
        );
        self::assertStringContainsString("'fixed_total'", $service);
        self::assertStringContainsString("'movable_total'", $service);
        self::assertStringContainsString("'mode'", $service);

        $viewPath = dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php';
        $navPath = dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/components/content-project-list-month-nav.blade.php';
        $matrixPath = dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/components/content-project-list-monthly-planning.blade.php';
        self::assertFileExists($viewPath);
        self::assertFileExists($navPath);
        self::assertFileExists($matrixPath);
        $view = (string) file_get_contents($viewPath);
        $nav = (string) file_get_contents($navPath);
        $matrix = (string) file_get_contents($matrixPath);
        self::assertStringContainsString('content-project-list-month-nav', $view);
        self::assertStringContainsString('planning-matrix', $view);
        self::assertStringContainsString('content-project-month-charts', $view);
        self::assertStringNotContainsString('id="planning-month"', $view);
        self::assertStringContainsString('wire:model.live="planningMonth"', $nav);
        self::assertStringContainsString('active_month', $nav);
        self::assertStringContainsString('data-cp-list-month-nav', $nav);
        self::assertStringContainsString('data-cp-list-monthly-planning', $matrix);
        self::assertStringContainsString('list_monthly_planning_title', $matrix);
        self::assertStringContainsString('is-active', $matrix);
        self::assertStringNotContainsString('sitePlanningCellDetail', $matrix);
        self::assertStringNotContainsString('$wire.', $matrix);

        $plannerMatrix = dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/components/content-project-site-planning.blade.php';
        self::assertFileExists($plannerMatrix);
        $plannerSrc = (string) file_get_contents($plannerMatrix);
        self::assertStringContainsString('sitePlanningCellDetail', $plannerSrc);

        $plannerPage = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        self::assertStringContainsString('sitePlanningPayload', $plannerPage);
        self::assertStringNotContainsString('getMonthlyPlanningMatrix', $plannerPage);

        $globalSeoBar = dirname(__DIR__, 3).'/seo/resources/views/livewire/global-seo-bar.blade.php';
        if (is_file($globalSeoBar)) {
            $bar = (string) file_get_contents($globalSeoBar);
            self::assertStringNotContainsString('getMonthlyPlanningMatrix', $bar);
            self::assertStringNotContainsString('content-project-list-monthly-planning', $bar);
        }
    }
}
