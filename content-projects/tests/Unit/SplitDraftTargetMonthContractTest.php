<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SplitDraftContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Create Project modal — Target month (Draft stays monthless).
 */
final class SplitDraftTargetMonthContractTest extends TestCase
{
    public function test_modal_defaults_target_month_to_current(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        self::assertSame('2026-08', ContentProjectMonthContext::current());

        $trait = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithDraftSplit.php',
        );

        self::assertStringContainsString('public string $draftSplitTargetMonth', $trait);
        self::assertStringContainsString(
            '$this->draftSplitTargetMonth = ContentProjectMonthContext::current();',
            $trait,
        );
        self::assertSame(
            2,
            substr_count($trait, '$this->draftSplitTargetMonth = ContentProjectMonthContext::current();'),
            'default current month on mount + open modal',
        );

        Carbon::setTestNow();
    }

    public function test_can_select_previous_and_future_months(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        $options = ContentProjectMonthContext::selectOptions(12, 6);
        $values = array_column($options, 'value');

        self::assertContains('2026-07', $values);
        self::assertContains('2026-08', $values);
        self::assertContains('2026-09', $values);
        self::assertSame('07/2026', ContentProjectMonthContext::display('2026-07'));

        $blade = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        self::assertStringContainsString('data-split-field="target_month"', $blade);
        self::assertStringContainsString('wire:model.live="draftSplitTargetMonth"', $blade);
        self::assertStringContainsString('getDraftSplitTargetMonthOptions', $blade);

        Carbon::setTestNow();
    }

    public function test_selected_month_reaches_command_handler_and_service(): void
    {
        $cmd = new SplitDraftContentProjectCommand(
            1,
            SplitDraftContentProjectCommand::MODE_FIRST_N,
            10,
            [],
            false,
            [100],
            '2026-07',
        );
        self::assertSame('2026-07', $cmd->targetMonth);

        $handler = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectHandler::class))->getFileName(),
        );
        self::assertStringContainsString('$targetMonth = $command->targetMonth;', $handler);
        self::assertStringContainsString('$targetMonth,', $handler);

        $service = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectService::class))->getFileName(),
        );
        self::assertStringContainsString('resolveTargetMonth($targetMonth)', $service);
        self::assertStringContainsString('function resolveTargetMonth', $service);

        $factory = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Agent/ContentProjectAgentCommandFactory.php',
        );
        self::assertStringContainsString("isset(\$input['target_month'])", $factory);

        $trait = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithDraftSplit.php',
        );
        self::assertStringContainsString('targetMonth: $targetMonth', $trait);
    }

    public function test_capacity_and_packing_use_selected_month_not_forced_current(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        $service = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectService::class))->getFileName(),
        );
        $capacity = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectWriterMonthlyCapacityService::class))->getFileName(),
        );

        self::assertStringContainsString('planAllocations($taskIds, $assigneeIds, $month)', $service);
        self::assertStringContainsString("->whereDate('month', \$month->format('Y-m-d'))", $service);
        self::assertStringContainsString('itemBreakdownByUserId', $capacity);
        self::assertStringContainsString('archived_count', $capacity);
        self::assertStringContainsString('STATUS_DRAFT', $capacity);
        self::assertStringContainsString('Shared Planning Draft excluded', $capacity);

        $nullCmd = new SplitDraftContentProjectCommand(1);
        self::assertNull($nullCmd->targetMonth);

        $resolvedNull = ContentProjectMonthContext::normalize(null);
        self::assertSame('2026-08', $resolvedNull);
        self::assertSame('2026-07', ContentProjectMonthContext::normalize('2026-07'));
        self::assertSame('2026-07-01', ContentProjectMonthContext::toDateString('2026-07'));

        Carbon::setTestNow();
    }

    public function test_draft_items_remain_monthless_no_planned_month(): void
    {
        $paths = [
            dirname(__DIR__, 2).'/src/Services/ContentProject/Draft/SplitDraftContentProjectService.php',
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithDraftSplit.php',
            dirname(__DIR__, 2).'/src/Models/SeoProjectTask.php',
        ];

        foreach ($paths as $path) {
            $src = (string) file_get_contents($path);
            self::assertStringNotContainsString('planned_month', $src, basename($path));
        }

        $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*planned_month*') ?: [];
        self::assertSame([], $migrations);

        $service = (string) file_get_contents($paths[0]);
        self::assertStringContainsString('Draft itself stays monthless', $service);
    }

    public function test_lang_keys_for_target_month(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');

        self::assertStringContainsString("'draft_split_target_month'", $en);
        self::assertStringContainsString("'draft_split_target_month'", $vi);
    }
}
