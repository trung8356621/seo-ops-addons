<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemGenerationReadState;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Persist per-user Needs Review read-state (user + project item + completed_at viewed).
 * Presentation filter only — not lifecycle / generation SoT.
 */
final class ContentProjectGenerationReadStateStore
{
    public function tableReady(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasTable('seo_content_project_item_generation_read_states');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<int>  $projectItemIds
     * @return array<int, CarbonInterface> project_item_id => viewed_generation_completed_at
     */
    public function viewedCompletedAtByItemIds(int $userId, int $projectId, array $projectItemIds): array
    {
        if ($userId <= 0 || $projectId <= 0 || $projectItemIds === [] || ! $this->tableReady()) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $projectItemIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        $rows = SeoContentProjectItemGenerationReadState::query()
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->whereIn('project_item_id', $ids)
            ->get(['project_item_id', 'viewed_generation_completed_at']);

        $map = [];
        foreach ($rows as $row) {
            $itemId = (int) $row->project_item_id;
            $viewed = $row->viewed_generation_completed_at;
            if ($itemId > 0 && $viewed instanceof CarbonInterface) {
                $map[$itemId] = $viewed;
            }
        }

        return $map;
    }

    public function markViewed(
        int $userId,
        int $projectId,
        int $projectItemId,
        CarbonInterface $generationCompletedAt,
    ): bool {
        if ($userId <= 0 || $projectId <= 0 || $projectItemId <= 0 || ! $this->tableReady()) {
            return false;
        }

        try {
            $now = now();
            SeoContentProjectItemGenerationReadState::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'project_item_id' => $projectItemId,
                ],
                [
                    'project_id' => $projectId,
                    'viewed_generation_completed_at' => $generationCompletedAt,
                    'viewed_at' => $now,
                ],
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, CarbonInterface>  $completedAtByItemId
     */
    public function markManyViewed(int $userId, int $projectId, array $completedAtByItemId): int
    {
        if ($userId <= 0 || $projectId <= 0 || $completedAtByItemId === [] || ! $this->tableReady()) {
            return 0;
        }

        $count = 0;
        foreach ($completedAtByItemId as $itemId => $completedAt) {
            $tid = (int) $itemId;
            if ($tid <= 0 || ! $completedAt instanceof CarbonInterface) {
                continue;
            }
            if ($this->markViewed($userId, $projectId, $tid, $completedAt)) {
                $count++;
            }
        }

        return $count;
    }
}
