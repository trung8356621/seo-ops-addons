<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceClientSyncRules;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use PHPUnit\Framework\TestCase;

final class IdeaWorkspaceClientSyncRulesTest extends TestCase
{
    public function test_edit_during_inflight_save_keeps_dirty_and_reschedules(): void
    {
        $decision = IdeaWorkspaceClientSyncRules::afterSaveResponse(
            ['status' => IdeaWorkspacePersistenceService::STATUS_OK, 'revision' => 4],
            editGeneration: 8,
            editGenerationAtSend: 7,
            currentBaseRevision: 3,
        );

        self::assertFalse($decision['clear_dirty']);
        self::assertFalse($decision['overwrite_working_set']);
        self::assertTrue($decision['advance_base_revision']);
        self::assertSame(4, $decision['base_revision']);
        self::assertTrue($decision['reschedule_save']);
        self::assertSame('editing', $decision['sync_status']);
    }

    public function test_matching_generation_success_clears_dirty_without_overwrite(): void
    {
        $decision = IdeaWorkspaceClientSyncRules::afterSaveResponse(
            ['status' => IdeaWorkspacePersistenceService::STATUS_NOOP, 'revision' => 2],
            editGeneration: 5,
            editGenerationAtSend: 5,
            currentBaseRevision: 2,
        );

        self::assertTrue($decision['clear_dirty']);
        self::assertFalse($decision['overwrite_working_set']);
        self::assertSame('synced', $decision['sync_status']);
        self::assertFalse($decision['reschedule_save']);
    }

    public function test_stale_revision_conflict_never_overwrites_working_set(): void
    {
        $decision = IdeaWorkspaceClientSyncRules::afterSaveResponse(
            [
                'status' => IdeaWorkspacePersistenceService::STATUS_CONFLICT,
                'revision' => 9,
                'note_items' => [['cluster_ref' => 'topic:1']],
            ],
            editGeneration: 3,
            editGenerationAtSend: 3,
            currentBaseRevision: 8,
        );

        self::assertFalse($decision['clear_dirty']);
        self::assertFalse($decision['overwrite_working_set']);
        self::assertTrue($decision['show_conflict']);
        self::assertSame(8, $decision['base_revision']);
        self::assertSame('unsynced_error', $decision['sync_status']);
    }

    public function test_error_with_newer_edits_reschedules_retry(): void
    {
        $decision = IdeaWorkspaceClientSyncRules::afterSaveResponse(
            ['status' => 'forbidden'],
            editGeneration: 10,
            editGenerationAtSend: 9,
            currentBaseRevision: 1,
        );

        self::assertFalse($decision['clear_dirty']);
        self::assertTrue($decision['reschedule_save']);
        self::assertSame('unsynced_error', $decision['sync_status']);
    }

    public function test_reconcile_dirty_same_revision_schedules_save(): void
    {
        $r = IdeaWorkspaceClientSyncRules::reconcileOnOpen(true, 3, 3);
        self::assertTrue($r['use_local']);
        self::assertFalse($r['show_conflict']);
        self::assertTrue($r['schedule_save']);
    }

    public function test_reconcile_dirty_stale_base_shows_conflict_without_autosave(): void
    {
        $r = IdeaWorkspaceClientSyncRules::reconcileOnOpen(true, 2, 5);
        self::assertTrue($r['use_local']);
        self::assertTrue($r['show_conflict']);
        self::assertFalse($r['schedule_save']);
    }
}
