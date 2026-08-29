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
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectActionDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectGenerationGate;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskSyncService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Draft Split / Activate — reviewed-only + max-30 current-month contracts.
 */
final class SplitDraftContentProjectContractTest extends TestCase
{
    public function test_command_capability_and_handler_wiring(): void
    {
        $cmd = new SplitDraftContentProjectCommand(
            1,
            SplitDraftContentProjectCommand::MODE_FIRST_N,
            30,
            [],
            false,
        );
        self::assertSame('content_project.split_draft', $cmd->name());
        self::assertFalse(property_exists($cmd, 'splitMonths'));
        self::assertTrue(property_exists($cmd, 'assigneeIds'));
        self::assertSame([], $cmd->assigneeIds);

        $registrar = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );
        self::assertStringContainsString('SplitDraftContentProjectCommand::class => SplitDraftContentProjectHandler::class', $registrar);

        $registry = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Capabilities/ContentProjectCapabilityRegistry.php',
        );
        self::assertStringContainsString("'content_project.split_draft'", $registry);
        self::assertStringNotContainsString("'split_months'", $registry);

        $factory = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Agent/ContentProjectAgentCommandFactory.php',
        );
        self::assertStringContainsString("'content_project.split_draft' =>", $factory);
        self::assertStringContainsString('assignee_ids', $factory);
        self::assertStringNotContainsString('split_months', $factory);

        self::assertSame('project.not_draft', ContentProjectActionCodes::PROJECT_NOT_DRAFT);
        self::assertSame('draft.split', ContentProjectActionCodes::DRAFT_SPLIT);
        self::assertSame(30, ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS);
        self::assertSame(
            ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS,
            ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
        );
    }

    public function test_service_moves_same_task_ids_reviewed_only_and_real_writer(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectService::class))->getFileName(),
        );

        self::assertStringContainsString('lockForUpdate', $src);
        self::assertStringContainsString("'project_id' => (int) \$execution->getKey()", $src);
        self::assertStringContainsString('source_draft_project_id', $src);
        self::assertStringContainsString('STATUS_PENDING', $src);
        self::assertStringContainsString('assertTaskSplittable', $src);
        self::assertStringContainsString('assertTaskReviewed', $src);
        self::assertStringContainsString('orderedReviewedDraftTaskIds', $src);
        self::assertStringContainsString('planning_reviewed_at', $src);
        self::assertStringContainsString('planAllocations', $src);
        self::assertStringContainsString('ContentProjectWriterAllocator', $src);
        self::assertStringContainsString('nextExecutionProjectName', $src);
        self::assertStringContainsString('MAX_EXECUTION_PROJECT_ITEMS', $src);
        self::assertStringContainsString('ContentProjectExecutionPackingService', $src);
        self::assertStringContainsString('planPack', $src);
        self::assertStringContainsString("'user_id' => \$writerId", $src);
        self::assertStringContainsString("->where('user_id', \$userId)", $src);
        self::assertStringContainsString('reservedNamesByWriter', $src);
        self::assertStringContainsString('defaultNameFromMonth', $src);
        self::assertStringContainsString('SeoContentProjectItemOrigin', $src);
        self::assertStringContainsString('orderBy(\'id\')', $src);
        self::assertStringContainsString('auto_generate', $src);
        self::assertStringContainsString('forceFill([', $src);
        self::assertStringContainsString('normalizeUserIds', $src);
        self::assertStringNotContainsString('SeoOpsSystemUser::id()', $src);
        self::assertStringNotContainsString('insufficient_slots', $src);
        self::assertStringNotContainsString('remainingByUserId', $src);
        self::assertStringNotContainsString('partitionEvenly', $src);
        self::assertStringNotContainsString('normalizeSplitMonths', $src);
        self::assertStringNotContainsString('splitMonths', $src);
        self::assertStringNotContainsString("'loai_san_pham' =>", $src);
        self::assertStringNotContainsString('GenerateProjectItems', $src);
        self::assertStringNotContainsString('dispatch(new Generate', $src);
        self::assertStringNotContainsString("(\$lockedDraft->user_id ?? \$actorId", $src);
        self::assertStringNotContainsString('auth()->id()', $src);
    }

    public function test_allocator_fair_distributes_deterministically(): void
    {
        $result = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAllocator::allocate(
            range(1, 62),
            [1, 2, 3],
        );

        self::assertSame([21, 21, 20], array_column($result['allocations'], 'item_count'));
        self::assertSame(0, $result['unallocated_count']);
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
        self::assertStringContainsString('assigneeIds', $src);
        self::assertStringContainsString('draft_split_no_writers', $src);
        self::assertStringNotContainsString('insufficient_slots', $src);
        self::assertStringNotContainsString('splitMonths', $src);
    }

    public function test_capacity_gates_retired_cleanly(): void
    {
        $model = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Models/SeoProject.php',
        );
        self::assertStringContainsString('return PHP_INT_MAX;', $model);
        self::assertStringContainsString('defaultExecutionName', $model);
        self::assertStringContainsString('defaultNameFromMonth', $model);

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
        self::assertStringContainsString('restoreToSourceDraftAndDelete', $move);
        self::assertStringContainsString('hasStartedExecution', $move);
        self::assertStringContainsString('isRestorableUnstartedExecution', $move);
        self::assertStringContainsString('delete_blocked_already_started', $move);

        $migration = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_24_210000_add_source_draft_project_id_to_seo_projects_table.php',
        );
        self::assertStringContainsString('source_draft_project_id', $migration);
    }

    public function test_ui_split_reviewed_no_split_across_or_name_picker(): void
    {
        $draftPlanner = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $ops = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php');
        $trait = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithDraftSplit.php',
        );

        self::assertStringContainsString('data-draft-action="split"', $draftPlanner);
        self::assertStringContainsString('data-draft-action="activate-all"', $draftPlanner);
        self::assertStringContainsString('data-split-modal="1"', $draftPlanner);
        self::assertStringContainsString('x-teleport="body"', $draftPlanner);
        self::assertStringContainsString('cp-ops-dialog-overlay', $draftPlanner);
        self::assertStringContainsString('cp-ops-dialog', $draftPlanner);
        self::assertStringContainsString('wire:click="closeDraftSplitModal"', $draftPlanner);
        self::assertStringContainsString('draft_split_first_n', $draftPlanner);
        self::assertStringContainsString('draft_split_all', $draftPlanner);
        self::assertStringContainsString('draft_split_eligible', $draftPlanner);
        self::assertStringContainsString('draft_split_writers_heading', $draftPlanner);
        self::assertStringContainsString('draft_split_existing', $draftPlanner);
        self::assertStringContainsString('draft_split_new', $draftPlanner);
        self::assertStringContainsString('draft_split_result', $draftPlanner);
        self::assertStringContainsString('draft_split_projects_hint', $draftPlanner);
        self::assertStringContainsString("\$writer['project_count']", $draftPlanner);
        self::assertStringNotContainsString('draft_split_insufficient', $draftPlanner);
        self::assertStringNotContainsString('draft_split_full', $draftPlanner);
        self::assertStringContainsString('wire:model.live.debounce.300ms="draftSplitQuantity"', $draftPlanner);
        self::assertStringContainsString('excludeDraftSplitWriter', $draftPlanner);
        self::assertStringContainsString('includeDraftSplitWriter', $draftPlanner);
        self::assertStringContainsString('data-split-writers', $draftPlanner);
        self::assertStringContainsString('data-split-writer-included', $draftPlanner);
        self::assertStringContainsString('data-split-excluded', $draftPlanner);
        self::assertStringContainsString('cp-draft-split-layout', $draftPlanner);
        self::assertStringContainsString('wire:target="draftSplitQuantity,draftSplitMode,excludeDraftSplitWriter,includeDraftSplitWriter"', $draftPlanner);
        self::assertStringContainsString('cp-ops-dialog--split', $draftPlanner);
        self::assertStringContainsString("\$writer['new_allocation']", $draftPlanner);
        self::assertStringNotContainsString('draftSplitWriterIds', $draftPlanner);
        self::assertStringNotContainsString('data-split-preview', $draftPlanner);
        self::assertStringNotContainsString('cp-draft-split-preview', $draftPlanner);
        self::assertStringNotContainsString('draft_split_preview_heading', $draftPlanner);
        self::assertStringNotContainsString('type="checkbox"', $draftPlanner);
        self::assertStringNotContainsString("\$row['project_name']", $draftPlanner);
        self::assertStringNotContainsString('z-[70]', $draftPlanner);
        self::assertStringNotContainsString('data-split-field="month"', $draftPlanner);
        self::assertStringNotContainsString('data-split-field="name"', $draftPlanner);
        self::assertStringNotContainsString('data-split-field="months"', $draftPlanner);
        self::assertStringNotContainsString('data-split-months-stepper', $draftPlanner);
        self::assertStringNotContainsString('draft_split_months_count_label', $draftPlanner);
        self::assertStringNotContainsString('draft_split_schedule_heading', $draftPlanner);
        self::assertStringNotContainsString('draftSplitMonths', $draftPlanner);
        self::assertStringNotContainsString('decrementDraftSplitMonths', $draftPlanner);
        self::assertStringNotContainsString('draft_split_project_name', $draftPlanner);
        self::assertStringNotContainsString('wire:model="draftSplitMonth"', $draftPlanner);
        self::assertStringNotContainsString('wire:model="draftSplitName"', $draftPlanner);
        self::assertStringNotContainsString('max monthly', strtolower($draftPlanner));

        $opsStyles = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');
        self::assertStringContainsString('.cp-ops-dialog-overlay', $opsStyles);
        self::assertStringContainsString('z-index: 200', $opsStyles);
        self::assertStringContainsString('.cp-draft-split-layout', $opsStyles);
        self::assertStringContainsString('.cp-draft-split-writer-row', $opsStyles);
        self::assertStringContainsString('.cp-draft-split-metric--new', $opsStyles);
        self::assertStringNotContainsString('.cp-draft-split-preview', $opsStyles);

        self::assertStringContainsString('draft_empty_title', $ops);
        self::assertStringContainsString('project_no_assignee_badge', $ops);
        self::assertStringContainsString('openDraftSplitModal', $trait);
        self::assertStringContainsString('activateAllDraftItems', $trait);
        self::assertStringContainsString('draftSplitIncludedUserIds', $trait);
        self::assertStringContainsString('defaultEligibleIncludedUserIds', $trait);
        self::assertStringContainsString('excludeDraftSplitWriter', $trait);
        self::assertStringContainsString('includeDraftSplitWriter', $trait);
        self::assertStringContainsString('MAX_EXECUTION_PROJECT_ITEMS', $trait);
        self::assertStringContainsString('currentReviewedDraftItemCount', $trait);
        self::assertStringContainsString('assigneeIds:', $trait);
        self::assertStringContainsString('new_allocation', $trait);
        self::assertStringContainsString('resulting', $trait);
        self::assertStringContainsString('project_count', $trait);
        self::assertStringNotContainsString('draftSplitWriterIds', $trait);
        self::assertStringNotContainsString('full_writers', $trait);
        self::assertStringNotContainsString('insufficient_slots', $trait);
        self::assertStringNotContainsString("'preview' =>", $trait);
        self::assertStringNotContainsString('draftSplitMonths', $trait);
        self::assertStringNotContainsString('public string $draftSplitMonth', $trait);
        self::assertStringNotContainsString('public string $draftSplitName', $trait);
        self::assertStringNotContainsString('auth()->id() fallback', $trait);
        self::assertStringContainsString('MODE_ALL', $trait);
    }

    public function test_included_assignee_state_defaults_and_survives_quantity_updates(): void
    {
        $trait = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithDraftSplit.php',
        );

        self::assertStringContainsString('draftSplitIncludedUserIds = $this->defaultEligibleIncludedUserIds()', $trait);
        self::assertStringContainsString('function excludeDraftSplitWriter', $trait);
        self::assertStringContainsString('function includeDraftSplitWriter', $trait);
        self::assertStringContainsString('function orderedIncludedUserIds', $trait);
        self::assertMatchesRegularExpression(
            '/function updatedDraftSplitQuantity\(\): void\s*\{\s*\$this->clampDraftSplitInputs\(\);\s*\}/s',
            $trait,
        );
        self::assertMatchesRegularExpression(
            '/function updatedDraftSplitMode\(\): void\s*\{\s*\$this->clampDraftSplitInputs\(\);\s*\}/s',
            $trait,
        );
        self::assertStringContainsString("'new_allocation' => \$newAllocation", $trait);
        self::assertStringContainsString("'resulting' => \$current + \$newAllocation", $trait);
        self::assertStringContainsString("'project_count' => \$projectCount", $trait);
        self::assertStringContainsString('included_writers', $trait);
        self::assertStringContainsString('excluded_writers', $trait);
        self::assertStringNotContainsString('full_writers', $trait);
        self::assertSame(
            1,
            substr_count($trait, '$this->defaultEligibleIncludedUserIds()'),
            'defaultEligibleIncludedUserIds() must only be invoked on modal open/reset',
        );
    }

    public function test_generation_gate_blocks_missing_assignee(): void
    {
        $decision = ContentProjectProjectGenerationGate::resolve(
            [1, 2],
            conflictActive: false,
            conflictReason: ContentProjectProjectActionDecision::REASON_BULK_ACTIVE,
            noAssignee: true,
        );
        self::assertFalse($decision->enabled);
        self::assertSame(ContentProjectProjectActionDecision::REASON_NO_ASSIGNEE, $decision->reasonCode);

        $gateSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectProjectGenerationGate::class))->getFileName(),
        );
        self::assertStringContainsString('REASON_NO_ASSIGNEE', $gateSrc);
        self::assertStringContainsString('ContentProjectWriterAssignment::isUnassigned', $gateSrc);

        $handlerSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/GenerateProjectItemsHandler.php',
        );
        self::assertStringContainsString('no_assignee', $handlerSrc);
        self::assertStringContainsString('ContentProjectWriterAssignment::isUnassigned', $handlerSrc);

        $assignmentSrc = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Support/ContentProject/ContentProjectWriterAssignment.php',
        );
        self::assertStringContainsString('SeoOpsSystemUser::isSystemUserId', $assignmentSrc);

        \App\Services\Users\SeoOpsSystemUser::setCachedIdForTests(4242);
        $project = new \Omnichannel\Addons\ContentProjects\Models\SeoProject;
        $project->user_id = 4242;
        self::assertTrue(\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAssignment::isUnassigned($project));
        $project->user_id = 1001;
        self::assertTrue(\Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAssignment::hasRealWriter($project));
        \App\Services\Users\SeoOpsSystemUser::clearCache();
    }

    public function test_writer_month_uniqueness_retired(): void
    {
        $staff = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProjectStaffAvailabilityService.php',
        );
        self::assertStringContainsString("'assigned' => []", $staff);
        self::assertStringContainsString('assertUnassignedForMonth', $staff);
        self::assertStringContainsString('Month uniqueness retired', $staff);

        $create = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Pages/CreateSeoProject.php',
        );
        self::assertStringContainsString('return false;', $create);
        self::assertStringNotContainsString('assertUnassignedForMonth($userId', $create);

        $resource = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource.php',
        );
        self::assertStringContainsString('eligible_staff_heading', $resource);
    }

    public function test_delete_restore_contract_present(): void
    {
        $move = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectTaskMoveService::class))->getFileName(),
        );
        self::assertStringContainsString('STATUS_PENDING', $move);
        self::assertStringContainsString('source_draft_project_id', $move);
        self::assertStringContainsString('SeoProjectRun', $move);
        self::assertStringContainsString('SeoProjectRunItem', $move);
        self::assertStringContainsString('restoreToSourceDraftAndDelete', $move);
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
