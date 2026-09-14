<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * One-shot migrate: free models leave paid text tabs → Free Models area.
 * Preserves relative order; does not reshuffle paid priorities.
 */
final class AiFreeModelsAreaMigrator
{
    public const META_FLAG = 'omi_free_models_area_migrated_v1';

    public function __construct(
        private readonly AiModelPriorityService $priorities = new AiModelPriorityService(),
    ) {}

    public function migrateUserIfNeeded(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $moved = [];
        $rank = 1;

        // Collect free models currently on paid text areas, in area order then priority.
        foreach (AiModelArea::paidTextCases() as $area) {
            foreach ($this->priorities->areaEnabledModels($userId, $area) as $model) {
                if (! $this->isFreeModel($model)) {
                    continue;
                }
                $id = (int) $model->id;
                if (isset($moved[$id])) {
                    continue;
                }
                $moved[$id] = $rank;
                $rank++;
            }
        }

        if ($moved === []) {
            // Still strip any free leftovers that somehow remain enabled on paid tabs.
            $this->stripFreeFromPaidAreas($userId);

            return false;
        }

        // Enable on Free Models in collected order.
        $orderedIds = array_keys($moved);
        $this->priorities->appendToArea($userId, AiModelArea::FreeModels, $orderedIds);
        // Re-apply exact ranks.
        $this->priorities->reorderArea($userId, AiModelArea::FreeModels, $orderedIds);

        // Remove free from paid text areas (keep paid order untouched).
        foreach (AiModelArea::paidTextCases() as $area) {
            $this->priorities->removeFromArea($userId, $area, $orderedIds);
        }

        // Remove paid models that may have been wrongly placed on Free Models.
        $this->stripPaidFromFreeArea($userId);

        return true;
    }

    private function stripFreeFromPaidAreas(int $userId): void
    {
        foreach (AiModelArea::paidTextCases() as $area) {
            $freeIds = [];
            foreach ($this->priorities->areaEnabledModels($userId, $area) as $model) {
                if ($this->isFreeModel($model)) {
                    $freeIds[] = (int) $model->id;
                }
            }
            if ($freeIds !== []) {
                $this->priorities->removeFromArea($userId, $area, $freeIds);
                $this->priorities->appendToArea($userId, AiModelArea::FreeModels, $freeIds);
            }
        }
    }

    private function stripPaidFromFreeArea(int $userId): void
    {
        $paidIds = [];
        foreach ($this->priorities->areaEnabledModels($userId, AiModelArea::FreeModels) as $model) {
            if (! $this->isFreeModel($model)) {
                $paidIds[] = (int) $model->id;
            }
        }
        if ($paidIds !== []) {
            $this->priorities->removeFromArea($userId, AiModelArea::FreeModels, $paidIds);
        }
    }

    private function isFreeModel(SeoAiModel $model): bool
    {
        return OpenRouterModelEconomics::modelIsFree($model)
            || OpenRouterModelEconomics::isFree(
                is_array($model->capabilities) ? $model->capabilities : [],
                (string) $model->raw_model_name,
            );
    }
}
