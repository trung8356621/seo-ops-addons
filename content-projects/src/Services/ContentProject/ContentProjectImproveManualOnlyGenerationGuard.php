<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Server-side safety guard:
 * - «improve» is manual-only by default
 * - Generic generation / rerun must never enqueue AI pipeline for improve
 */
final class ContentProjectImproveManualOnlyGenerationGuard
{
    public const ALLOW_IMPROVE_GENERATION_SETTING = 'allow_improve_generation';

    /**
     * @param  list<int>  $itemIds
     * @param  array<int, string|null>  $typesById  task_id => task.type
     * @return array{
     *     eligible_ids: list<int>,
     *     skipped_improve_ids: list<int>,
     *     skipped_improve_count: int
     * }
     */
    public static function filterItemIds(
        array $itemIds,
        array $typesById,
        bool $allowImproveGeneration,
    ): array {
        $eligible = [];
        $skipped = [];

        foreach ($itemIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $type = SeoProjectTask::normalizeType((string) ($typesById[$id] ?? ''));
            if ($type === SeoProjectTask::TYPE_IMPROVE && ! $allowImproveGeneration) {
                $skipped[] = $id;
                continue;
            }

            $eligible[] = $id;
        }

        return [
            'eligible_ids' => $eligible,
            'skipped_improve_ids' => $skipped,
            'skipped_improve_count' => count($skipped),
        ];
    }
}

