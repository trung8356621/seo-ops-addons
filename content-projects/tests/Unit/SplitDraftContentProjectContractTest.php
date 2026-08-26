<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectSuggestionDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SplitDraftContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectImproveManualOnlyGenerationGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectGenerationGate;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Phase 3 — Draft Split / Activate + unlimited monthly capacity contracts.
 */
final class SplitDraftContentProjectContractTest extends TestCase
{
    public function test_command_capability_and_handler_wiring(): void
    {
        $cmd = new SplitDraftContentProjectCommand(1, SplitDraftContentProjectCommand::MODE_FIRST_N, 30);
        self::assertSame('content_project.split_draft', $cmd->name());

        $registrar = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );
        self::assertStringContainsString('SplitDraftContentProjectCommand::class => SplitDraftContentProjectHandler::class', $registrar);

        $registry = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Capabilities/ContentProjectCapabilityRegistry.php',
        );
        self::assertStringContainsString("'content_project.split_draft'", $registry);

        $factory = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Agent/ContentProjectAgentCommandFactory.php',
        );
        self::assertStringContainsString("'content_project.split_draft' =>", $factory);

        self::assertSame('project.not_draft', ContentProjectActionCodes::PROJECT_NOT_DRAFT);
        self::assertSame('draft.split', ContentProjectActionCodes::DRAFT_SPLIT);
    }

    public function test_service_moves_same_task_ids_and_preserves_origins(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectService::class))->getFileName(),
        );

        self::assertStringContainsString('lockForUpdate', $src);
        self::assertStringContainsString("'project_id' => (int) \$execution->getKey()", $src);
        self::assertStringContainsString('source_draft_project_id', $src);
        self::assertStringContainsString('STATUS_PENDING', $src);
        self::assertStringContainsString('assertTaskSplittable', $src);
        self::assertStringContainsString('SeoContentProjectItemOrigin', $src);
        self::assertStringContainsString('orderBy(\'id\')', $src);
        self::assertStringContainsString('auto_generate', $src);
        // Split moves the same task row — Product Type / Gallery Description columns stay untouched.
        self::assertStringContainsString('forceFill([', $src);
        self::assertStringNotContainsString("'loai_san_pham' =>", $src);
        self::assertStringNotContainsString('GenerateProjectItems', $src);
        self::assertStringNotContainsString('dispatch(new Generate', $src);
    }

    public function test_handler_has_no_filament_dependency(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectHandler::class))->getFileName(),
        );

        self::assertStringNotContainsString('Filament', $src);
        self::assertStringNotContainsString('SeoProjectResource', $src);
        self::assertStringContainsString('PROJECT_NOT_DRAFT', $src);
        self::assertStringContainsString('dryRun', $src);
    }

    public function test_capacity_gates_retired_cleanly(): void
    {
        $model = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Models/SeoProject.php',
        );
        self::assertStringContainsString('return PHP_INT_MAX;', $model);
        self::assertStringContainsString('defaultExecutionName', $model);

        $sync = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectTaskSyncService::class))->getFileName(),
        );
        self::assertStringContainsString('intentionally no-op', $sync);
        self::assertStringContainsString('return PHP_INT_MAX;', $sync);
        self::assertStringNotContainsString('daysInMonth', $sync);

        $move = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/SeoProjectTaskMoveService.php',
        );
        self::assertStringContainsString('assertTargetAcceptsMoves', $move);
        self::assertStringContainsString('move_target_option_items', $move);

        $migration = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_24_210000_add_source_draft_project_id_to_seo_projects_table.php',
        );
        self::assertStringContainsString('source_draft_project_id', $migration);
    }

    public function test_ui_split_activate_and_empty_draft_copy(): void
    {
        $draftPlanner = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $ops = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php');
        $trait = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithDraftSplit.php',
        );

        self::assertStringContainsString('data-draft-action="split"', $draftPlanner);
        self::assertStringContainsString('data-draft-action="activate-all"', $draftPlanner);
        self::assertStringContainsString('draft_split_first_n', $draftPlanner);
        self::assertStringContainsString('draft_split_selected', $draftPlanner);
        self::assertStringContainsString('draft_split_all', $draftPlanner);
        self::assertStringContainsString('draft_split_month', $draftPlanner);
        self::assertStringContainsString('draft_split_project_name', $draftPlanner);
        self::assertStringNotContainsString('max monthly', strtolower($draftPlanner));
        self::assertStringNotContainsString('daily capacity', strtolower($draftPlanner));

        self::assertStringContainsString('draft_empty_title', $ops);
        self::assertStringContainsString('openDraftSplitModal', $trait);
        self::assertStringContainsString('activateAllDraftItems', $trait);
        self::assertStringContainsString('MODE_ALL', $trait);
    }

    public function test_planner_history_and_rejection_owned_by_draft_constants(): void
    {
        self::assertSame('seo_audit', SeoContentProjectPlannerRun::SOURCE_SEO_AUDIT);
        self::assertSame('ai_new_content', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT);
        self::assertSame('dismissed', SeoContentProjectSuggestionDecision::DECISION_DISMISSED);
        self::assertTrue(property_exists(new SeoContentProjectItemOrigin, 'project_task_id')
            || method_exists(SeoContentProjectItemOrigin::class, 'getTable'));
    }

    public function test_improve_manual_guard_still_referenced(): void
    {
        self::assertTrue(class_exists(ContentProjectImproveManualOnlyGenerationGuard::class));
        self::assertTrue(class_exists(ContentProjectDraftExecutionGuard::class));
        self::assertTrue(class_exists(ContentProjectProjectGenerationGate::class));
    }
}
