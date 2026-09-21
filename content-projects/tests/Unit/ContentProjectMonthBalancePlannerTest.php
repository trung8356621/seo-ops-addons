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

    public function test_zero_and_sixty_one_splits_thirty_thirty_one(): void
    {
        $movable = [];
        for ($i = 1; $i <= 61; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-09'];
        }

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 0, '2026-09' => 0],
            $movable,
        );

        self::assertSame(30, $plan['target_by_month']['2026-08']);
        self::assertSame(31, $plan['target_by_month']['2026-09']);
        self::assertSame(30, $plan['move_count']);
        self::assertSame(1, $plan['imbalance_after']);
    }

    public function test_fixed_plus_movable_balances_final_load(): void
    {
        $movable = [];
        for ($i = 1; $i <= 3; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-08'];
        }
        for ($i = 4; $i <= 44; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-09'];
        }

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 17, '2026-09' => 0],
            $movable,
        );

        self::assertSame(61, array_sum($plan['target_by_month']));
        self::assertSame(30, $plan['target_by_month']['2026-08']);
        self::assertSame(31, $plan['target_by_month']['2026-09']);
        self::assertSame(13, $plan['target_movable_by_month']['2026-08']);
        self::assertSame(31, $plan['target_movable_by_month']['2026-09']);
    }

    public function test_over_ideal_fixed_never_forces_below_fixed(): void
    {
        $movable = [];
        for ($i = 1; $i <= 10; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-09'];
        }

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 35, '2026-09' => 0],
            $movable,
        );

        self::assertGreaterThanOrEqual(35, $plan['target_by_month']['2026-08']);
        self::assertSame(35, $plan['fixed_by_month']['2026-08']);
        self::assertSame(45, array_sum($plan['target_by_month']));
        self::assertSame(0, $plan['target_movable_by_month']['2026-08']);
        self::assertSame(10, $plan['target_movable_by_month']['2026-09']);
    }

    public function test_three_month_water_fill_with_heavy_fixed(): void
    {
        $movable = [];
        for ($i = 1; $i <= 20; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-09'];
        }

        $plan = $this->planner->plan(
            ['2026-07', '2026-08', '2026-09'],
            ['2026-07' => 40, '2026-08' => 0, '2026-09' => 0],
            $movable,
        );

        self::assertSame(40, $plan['target_by_month']['2026-07']);
        self::assertSame(10, $plan['target_by_month']['2026-08']);
        self::assertSame(10, $plan['target_by_month']['2026-09']);
    }

    public function test_already_balanced_thirty_thirty_one_is_noop(): void
    {
        $movable = [];
        for ($i = 1; $i <= 30; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-08'];
        }
        for ($i = 31; $i <= 61; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-09'];
        }

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 0, '2026-09' => 0],
            $movable,
        );

        self::assertSame(30, $plan['target_by_month']['2026-08']);
        self::assertSame(31, $plan['target_by_month']['2026-09']);
        self::assertSame(0, $plan['move_count']);
    }

    public function test_already_balanced_thirty_one_thirty_prefers_existing(): void
    {
        $movable = [];
        for ($i = 1; $i <= 31; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-08'];
        }
        for ($i = 32; $i <= 61; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-09'];
        }

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 0, '2026-09' => 0],
            $movable,
        );

        self::assertSame(31, $plan['target_by_month']['2026-08']);
        self::assertSame(30, $plan['target_by_month']['2026-09']);
        self::assertSame(0, $plan['move_count']);
    }

    public function test_deterministic_repeated_runs(): void
    {
        $movable = [];
        for ($i = 1; $i <= 61; $i++) {
            $movable[] = ['id' => $i, 'month' => '2026-09'];
        }

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
            ['id' => 3, 'month' => '2026-10'], // ignored — not selected
        ];

        $plan = $this->planner->plan(
            ['2026-08', '2026-09'],
            ['2026-08' => 0, '2026-09' => 0],
            $movable,
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
        self::assertStringContainsString('compact_success_items', $list);

        $viewPath = dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php';
        $navPath = dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/components/content-project-list-month-nav.blade.php';
        self::assertFileExists($viewPath);
        self::assertFileExists($navPath);
        $view = (string) file_get_contents($viewPath);
        $nav = (string) file_get_contents($navPath);
        self::assertStringContainsString('content-project-list-month-nav', $view);
        self::assertStringNotContainsString('id="planning-month"', $view);
        self::assertStringContainsString('wire:model.live="planningMonth"', $nav);
        self::assertStringContainsString('active_month', $nav);
        self::assertStringContainsString('data-cp-list-month-nav', $nav);
    }
}
