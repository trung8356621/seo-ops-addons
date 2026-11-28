<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\MoveSharedDraftToIdeaManagerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerKeywordIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceItemOps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * Contract & behavioral tests for Shared Planning Draft → Idea Manager MOVE (Row + Bulk).
 */
final class MoveSharedDraftToIdeaManagerContractTest extends TestCase
{
    public function test_move_service_uses_canonical_intake_and_archive_command_without_raw_delete(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(MoveSharedDraftToIdeaManagerService::class))->getFileName()
        );

        self::assertStringContainsString('IdeaManagerKeywordIntakeService', $src);
        self::assertStringContainsString('ContentProjectCommandBus', $src);
        self::assertStringContainsString('ArchiveProjectItemsCommand', $src);
        self::assertStringContainsString('REASON_GLOBAL_SKIP', $src);
        self::assertStringContainsString('ContentProjectItemStateResolver', $src);
        self::assertStringContainsString('skipped_unsafe', $src);

        // Must never bypass canonical deletion or import non-keyword metadata.
        self::assertStringNotContainsString('->delete()', $src);
        self::assertStringNotContainsString('DB::delete', $src);
        self::assertStringNotContainsString('$task->title', $src);
        self::assertStringNotContainsString('$task->description', $src);
        self::assertStringNotContainsString('prompt_result', $src);
    }

    public function test_move_service_protects_linked_article_review_publish_and_running_tasks(): void
    {
        $service = new ReflectionClass(MoveSharedDraftToIdeaManagerService::class);
        $detect = new ReflectionMethod(MoveSharedDraftToIdeaManagerService::class, 'detectUnsafeLinkedReason');
        $detect->setAccessible(true);

        $src = (string) file_get_contents((string) $service->getFileName());
        self::assertStringContainsString('STATUS_WRITING', $src);
        self::assertStringContainsString('STATUS_PROCESSING', $src);
        self::assertStringContainsString('isWaitingOutlineReview', $src);
        self::assertStringContainsString('runItems()', $src);
        self::assertStringContainsString('ContentProjectItemPublishState::None', $src);
        self::assertStringContainsString('ContentProjectPublishQueueStatus::None', $src);
        self::assertStringContainsString('content_manager_reviewed_at', $src);
        self::assertStringContainsString('ContentProjectItemReviewState::None', $src);
        self::assertStringContainsString('isNewArticleType', $src);
        self::assertStringContainsString('ContentProjectItemGenerationState::Completed', $src);
        self::assertStringContainsString('$state->executionState', $src);
        self::assertStringContainsString('$state->publishState', $src);
        self::assertStringContainsString('$state->reviewState', $src);
        self::assertStringContainsString('$state->generationState', $src);
    }

    public function test_detect_unsafe_linked_reason_executes_state_resolver_without_undefined_property_error(): void
    {
        $service = new MoveSharedDraftToIdeaManagerService(
            new IdeaManagerKeywordIntakeService(new IdeaWorkspacePersistenceService),
            (new ReflectionClass(ContentProjectCommandBus::class))->newInstanceWithoutConstructor(),
        );
        $detect = new ReflectionMethod(MoveSharedDraftToIdeaManagerService::class, 'detectUnsafeLinkedReason');
        $detect->setAccessible(true);

        $makeTask = static function (array $attrs = []): SeoProjectTask {
            $task = (new ReflectionClass(SeoProjectTask::class))->newInstanceWithoutConstructor();
            $task->setRawAttributes(array_merge([
                'site_id' => 7,
                'type' => SeoProjectTask::TYPE_CREATE,
                'status' => SeoProjectTask::STATUS_PENDING,
                'keyword' => 'capsule wardrobe',
                'publish_queue_status' => 'none',
            ], $attrs), true);
            $task->setRelation('article', null);
            $task->setRelation('runItems', new \Illuminate\Database\Eloquent\Collection);

            return $task;
        };

        // Clean draft task must be safe (null) and not throw Undefined property $execution/$publish/$review/$generation.
        self::assertNull($detect->invoke($service, $makeTask()));

        // Unsafe linked tasks must return a non-empty skip reason.
        self::assertNotNull($detect->invoke($service, $makeTask(['status' => SeoProjectTask::STATUS_WRITING])));
        self::assertNotNull($detect->invoke($service, $makeTask(['article_id' => 55])));
        self::assertNotNull($detect->invoke($service, $makeTask(['publish_queue_status' => 'waiting'])));
        self::assertNotNull($detect->invoke($service, $makeTask(['status' => SeoProjectTask::STATUS_REVIEWING])));
    }

    public function test_planner_page_exposes_row_and_bulk_move_actions_using_shared_service(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName()
        );

        self::assertStringContainsString('public function moveToIdeaManagerOne(int $taskId): array', $src);
        self::assertStringContainsString('public function moveToIdeaManagerSelected(mixed $taskIds = null): array', $src);
        self::assertStringContainsString('MoveSharedDraftToIdeaManagerService::class', $src);
        self::assertStringContainsString('draftPlanningRefreshNonce++', $src);
        self::assertStringContainsString('selectedTaskIds', $src);
    }

    public function test_draft_items_blade_wires_row_and_bulk_move_actions_safely(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('data-draft-row-action="move-to-idea"', $blade);
        self::assertStringContainsString('data-draft-bulk-action="move-to-idea"', $blade);
        self::assertStringContainsString('moveToIdeaRow(row)', $blade);
        self::assertStringContainsString('bulkMoveToIdea()', $blade);
        self::assertStringContainsString('moveToIdeaManagerOne(row.id)', $blade);
        self::assertStringContainsString('moveToIdeaManagerSelected(ids)', $blade);
        self::assertStringContainsString('confirmMoveToIdea', $blade);
        self::assertStringContainsString('confirmBulkMoveToIdea', $blade);
        self::assertStringContainsString('moveToIdeaManagerSelected,moveToIdeaManagerOne', $blade);
    }

    public function test_translations_exist_in_vi_and_en(): void
    {
        $vi = LegacyAddonPath::read('lang/vi/filament.php');
        $en = LegacyAddonPath::read('lang/en/filament.php');

        foreach ([
            'planning_move_to_idea',
            'planning_move_to_idea_confirm',
            'planning_bulk_move_to_idea_confirm',
            'planning_move_to_idea_completed',
            'planning_move_to_idea_already',
            'planning_move_to_idea_partial',
            'planning_move_to_idea_failed',
        ] as $key) {
            self::assertStringContainsString("'{$key}'", $vi, "Missing vi key: {$key}");
            self::assertStringContainsString("'{$key}'", $en, "Missing en key: {$key}");
        }
    }

    public function test_payload_and_dedupe_preserve_only_keyword_and_provenance(): void
    {
        $made = IdeaWorkspaceItemOps::makeManualSeed('phong cách capsule wardrobe', []);
        self::assertFalse($made['duplicate']);
        self::assertNotNull($made['item']);

        $item = $made['item'];
        $item['source_site_id'] = 4;
        $item['source_domain'] = 'thoitrang4.example';

        $normalized = AuditNoteDnaNormalizer::normalizeNoteItem($item);
        self::assertIsArray($normalized);
        self::assertSame('manual_seed', $normalized['source_type']);
        self::assertSame('phong cách capsule wardrobe', $normalized['seed_text']);
        self::assertSame(4, $normalized['source_site_id']);
        self::assertSame('thoitrang4.example', $normalized['source_domain']);
        self::assertSame([], $normalized['dna']);

        // Exact duplicate / retry does not create a second Idea.
        $retry = IdeaWorkspaceItemOps::makeManualSeed('  Phong Cách Capsule Wardrobe  ', [$normalized]);
        self::assertTrue($retry['duplicate']);
        self::assertNull($retry['item']);
    }
}
