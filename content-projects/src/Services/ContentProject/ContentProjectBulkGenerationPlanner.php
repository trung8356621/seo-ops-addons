<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;

/**
 * Splits Generate working items selection into normal first-generation vs keyword restart.
 */
final class ContentProjectBulkGenerationPlanner
{
    /**
     * @param  list<int>  $selectedTaskIds
     * @return array{
     *     generate_ids: list<int>,
     *     restart_with_keyword_ids: list<int>,
     * }
     */
    public function partition(ContentProjectGeneratePendingPreview $preview, array $selectedTaskIds): array
    {
        $allowed = [];
        foreach ($preview->runDecisions() as $decision) {
            $allowed[$decision->taskId] = $decision;
        }

        $generateIds = [];
        $restartIds = [];

        foreach ($selectedTaskIds as $rawId) {
            $taskId = (int) $rawId;
            $decision = $allowed[$taskId] ?? null;
            if (! $decision instanceof ContentProjectItemGenerationDecision || ! $decision->shouldRun()) {
                continue;
            }

            if ($decision->reason === ContentProjectGenerationKeyword::REASON_DIRTY) {
                $restartIds[] = $taskId;
                continue;
            }

            $generateIds[] = $taskId;
        }

        return [
            'generate_ids' => array_values(array_unique($generateIds)),
            'restart_with_keyword_ids' => array_values(array_unique($restartIds)),
        ];
    }
}
