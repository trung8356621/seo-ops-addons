<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

/**
 * Pure decision helpers mirroring Idea Manager Alpine autosave / conflict rules.
 * Keeps edit-during-inflight and conflict semantics regression-testable in PHP.
 */
final class IdeaWorkspaceClientSyncRules
{
    /**
     * @param  array{status: string, revision?: int, note_items?: list<array<string, mixed>>}  $result
     * @return array{
     *   clear_dirty: bool,
     *   overwrite_working_set: bool,
     *   advance_base_revision: bool,
     *   base_revision: int,
     *   show_conflict: bool,
     *   reschedule_save: bool,
     *   sync_status: string
     * }
     */
    public static function afterSaveResponse(
        array $result,
        int $editGeneration,
        int $editGenerationAtSend,
        int $currentBaseRevision,
    ): array {
        $newer = $editGeneration > $editGenerationAtSend;
        $status = (string) ($result['status'] ?? '');
        $revision = (int) ($result['revision'] ?? $currentBaseRevision);

        if ($status === IdeaWorkspacePersistenceService::STATUS_CONFLICT) {
            return [
                'clear_dirty' => false,
                'overwrite_working_set' => false,
                'advance_base_revision' => false,
                'base_revision' => $currentBaseRevision,
                'show_conflict' => true,
                'reschedule_save' => false,
                'sync_status' => 'unsynced_error',
            ];
        }

        if ($status === IdeaWorkspacePersistenceService::STATUS_OK
            || $status === IdeaWorkspacePersistenceService::STATUS_NOOP) {
            return [
                'clear_dirty' => ! $newer,
                'overwrite_working_set' => false,
                'advance_base_revision' => true,
                'base_revision' => $revision,
                'show_conflict' => false,
                'reschedule_save' => $newer,
                'sync_status' => $newer ? 'editing' : 'synced',
            ];
        }

        return [
            'clear_dirty' => false,
            'overwrite_working_set' => false,
            'advance_base_revision' => false,
            'base_revision' => $currentBaseRevision,
            'show_conflict' => false,
            'reschedule_save' => $newer,
            'sync_status' => 'unsynced_error',
        ];
    }

    /**
     * @return array{use_local: bool, show_conflict: bool, schedule_save: bool}
     */
    public static function reconcileOnOpen(bool $localDirty, int $localBaseRevision, int $serverRevision): array
    {
        if (! $localDirty) {
            return ['use_local' => false, 'show_conflict' => false, 'schedule_save' => false];
        }
        if ($localBaseRevision === $serverRevision) {
            return ['use_local' => true, 'show_conflict' => false, 'schedule_save' => true];
        }

        return ['use_local' => true, 'show_conflict' => true, 'schedule_save' => false];
    }
}
