<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Contracts\DecisionModelSource;
use Omnichannel\Addons\AiPrompt\Contracts\ResolvedDecisionModel;
use Omnichannel\Addons\AiPrompt\Models\AiRoutingProfile;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * AI Settings order for the Decision Models group.
 * Disabled profile or disabled area membership yields an empty list.
 */
final class DecisionModelResolver implements DecisionModelSource
{
    public function __construct(
        private readonly AiModelPriorityService $priorities = new AiModelPriorityService(),
        private readonly ModelCapabilityRegistry $capabilities = new ModelCapabilityRegistry(),
        private readonly AiModelFamilyCatalog $families = new AiModelFamilyCatalog(),
    ) {}

    public function orderedModels(int $userId): array
    {
        $profile = AiExecutionProfile::DecisionRoute;
        if (! $this->profileEnabled($userId, $profile)) {
            return [];
        }

        $required = $profile->requiredCapabilityKeys();
        $allowedFamilies = $this->allowedFamilyKeys($userId, $profile);
        $models = [];
        foreach ($this->priorities->effectiveAreaModels($userId, AiModelArea::Decision) as $model) {
            if (! $model instanceof SeoAiModel) {
                continue;
            }
            $connection = $model->apiConnection;
            if (! $connection instanceof ApiConnection) {
                continue;
            }
            $raw = (string) $model->raw_model_name;
            if (! $this->capabilities->satisfiesAll($connection, $raw, $required)) {
                continue;
            }
            $family = $this->families->familyForModelId($raw);
            $familyKey = $family?->familyKey ?? '';
            if ($allowedFamilies !== [] && ! in_array($familyKey, $allowedFamilies, true)) {
                continue;
            }
            $models[] = [
                'family' => $familyKey,
                'priority' => (int) ($model->getAttribute('effective_area_priority') ?? $model->priority ?? 100),
                'model' => new ResolvedDecisionModel(
                    connectionId: (int) $connection->id,
                    provider: (string) $connection->provider,
                    model: $raw,
                    displayName: (string) ($model->display_name ?: $raw),
                    priority: (int) ($model->getAttribute('effective_area_priority') ?? $model->priority ?? 100),
                ),
            ];
        }

        if ($allowedFamilies !== []) {
            usort($models, static function (array $a, array $b) use ($allowedFamilies): int {
                $ai = array_search($a['family'], $allowedFamilies, true);
                $bi = array_search($b['family'], $allowedFamilies, true);
                $ai = $ai === false ? PHP_INT_MAX : $ai;
                $bi = $bi === false ? PHP_INT_MAX : $bi;

                return $ai <=> $bi;
            });
        }

        return array_map(
            static fn (array $row): ResolvedDecisionModel => $row['model'],
            $models,
        );
    }

    private function profileEnabled(int $userId, AiExecutionProfile $profile): bool
    {
        if (! class_exists(AiRoutingProfile::class)) {
            return true;
        }

        $row = AiRoutingProfile::query()
            ->where('user_id', $userId)
            ->where('key', $profile->value)
            ->first();
        if ($row === null) {
            return true;
        }

        return (bool) $row->enabled;
    }

    /**
     * @return list<string>
     */
    private function allowedFamilyKeys(int $userId, AiExecutionProfile $profile): array
    {
        $settings = (new AiRoutingTargetService($this->capabilities))->profileSettings($userId, $profile);
        $keys = $settings['allowed_family_keys'] ?? [];
        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => trim((string) $key), $keys),
            static fn (string $key): bool => $key !== '' && $key !== AiModelFamilyCatalog::AUTOMATIC,
        ));
    }
}
