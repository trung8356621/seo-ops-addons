<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\WpOption;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * One-shot migrate: free models leave paid text tabs → Free Models area.
 * Preserves relative order; does not reshuffle paid priorities after META_FLAG.
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

        if ($this->isMigrated($userId)) {
            // Repair only: strip accidental free leftovers from paid tabs — no Free Models reorder.
            $this->stripFreeFromPaidAreas($userId, reorderFreeModels: false);

            return false;
        }

        $moved = [];
        $rank = 1;

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
            $this->stripFreeFromPaidAreas($userId, reorderFreeModels: false);
            $this->stripPaidFromFreeArea($userId);
            $this->markMigrated($userId);

            return false;
        }

        $orderedIds = array_keys($moved);
        // Append into Free Models preserving existing Free Models ranks; then rank only newcomers
        // relative to each other at the end — do not wipe prior Free Models order.
        $existingFree = array_map(
            static fn (SeoAiModel $m): int => (int) $m->id,
            $this->priorities->areaEnabledModels($userId, AiModelArea::FreeModels),
        );
        $newOnly = array_values(array_filter(
            $orderedIds,
            static fn (int $id): bool => ! in_array($id, $existingFree, true),
        ));
        if ($newOnly !== []) {
            $this->priorities->appendToArea($userId, AiModelArea::FreeModels, $newOnly);
        }

        foreach (AiModelArea::paidTextCases() as $area) {
            $this->priorities->removeFromArea($userId, $area, $orderedIds);
        }

        $this->stripPaidFromFreeArea($userId);
        $this->markMigrated($userId);

        return true;
    }

    public function isMigrated(int $userId): bool
    {
        return (bool) WpOption::get($this->optionKey($userId), false);
    }

    private function markMigrated(int $userId): void
    {
        WpOption::set($this->optionKey($userId), true);
    }

    private function optionKey(int $userId): string
    {
        return self::META_FLAG.'.'.$userId;
    }

    /**
     * @param  bool  $reorderFreeModels  Must stay false after META_FLAG (no Free Models reorder).
     */
    private function stripFreeFromPaidAreas(int $userId, bool $reorderFreeModels = false): void
    {
        unset($reorderFreeModels);
        foreach (AiModelArea::paidTextCases() as $area) {
            $freeIds = [];
            foreach ($this->priorities->areaEnabledModels($userId, $area) as $model) {
                if ($this->isFreeModel($model)) {
                    $freeIds[] = (int) $model->id;
                }
            }
            if ($freeIds === []) {
                continue;
            }
            $this->priorities->removeFromArea($userId, $area, $freeIds);
            $already = [];
            foreach ($this->priorities->areaEnabledModels($userId, AiModelArea::FreeModels) as $model) {
                $already[(int) $model->id] = true;
            }
            $toAppend = array_values(array_filter(
                $freeIds,
                static fn (int $id): bool => ! isset($already[$id]),
            ));
            if ($toAppend !== []) {
                $this->priorities->appendToArea($userId, AiModelArea::FreeModels, $toAppend);
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
