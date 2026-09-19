<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRecommendedModelAutoMapResult;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiCanonicalModelKey;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Repair / bootstrap: map known recommended inventory into omi_areas.
 *
 * SSOT for membership remains {@see AiModelPriorityService}. Does not touch
 * ai_routing_profiles / ai_routing_targets. Does not call provider APIs.
 */
final class AiRecommendedModelMapper
{
    public function __construct(
        private readonly AiRecommendedModelCatalog $catalog = new AiRecommendedModelCatalog(),
        private readonly ?AiModelPriorityService $priorities = null,
        private readonly ?AiModelInventory $inventory = null,
    ) {}

    private function priorities(): AiModelPriorityService
    {
        return $this->priorities ?? app(AiModelPriorityService::class);
    }

    private function inventory(): AiModelInventory
    {
        return $this->inventory ?? app(AiModelInventory::class);
    }

    public function mapForUser(int $userId, ?AiModelArea $area = null): AiRecommendedModelAutoMapResult
    {
        $priorities = $this->priorities();
        $result = new AiRecommendedModelAutoMapResult();
        $areas = $area !== null ? [$area] : $this->mappableAreas();

        /** @var array<string, list<SeoAiModel>> $groups provider|canonical => models */
        $groups = [];
        foreach ($priorities->aiConnections($userId) as $connection) {
            if ((string) $connection->status !== 'active'
                || ! AiConnectionCredential::isUsable($connection->api_key)) {
                continue;
            }
            $models = SeoAiModel::query()
                ->where('api_connection_id', $connection->id)
                ->where('status', SeoAiModel::STATUS_ACTIVE)
                ->orderBy('id')
                ->get();
            foreach ($models as $model) {
                $model->setRelation('apiConnection', $connection);
                $result = $result->withIncrement('scanned');
                $canonical = AiCanonicalModelKey::fromProviderModelId(
                    (string) $model->raw_model_name,
                    (string) $connection->provider,
                );
                $groupKey = (string) $connection->provider.'|'.$canonical;
                $groups[$groupKey][] = $model;
            }
        }

        /** @var array<string, true> $seenProviderLogical provider|canonical|area */
        $seenProviderLogical = [];
        foreach ($groups as $groupKey => $siblings) {
            $canonical = (string) substr($groupKey, (int) strpos($groupKey, '|') + 1);
            $entry = $this->catalog->recommendedFor($canonical);
            if ($entry === null) {
                // Not in recommended catalog → remain Available (unknown / non-recommended).
                $result = $result->withIncrement('unknown');

                continue;
            }

            $targetArea = $entry['default_area'];
            if (! in_array($targetArea, $areas, true)) {
                continue;
            }

            // One explicit representative per provider|canonical|area.
            // Same-provider siblings share $groupKey; different providers must each map.
            $providerLogicalKey = $groupKey.'|'.$targetArea->value;
            if (isset($seenProviderLogical[$providerLogicalKey])) {
                continue;
            }
            $seenProviderLogical[$providerLogicalKey] = true;
            $result = $result->withIncrement('recognized');

            $decision = $this->decideGroup($siblings, $targetArea, $priorities);
            if ($decision === 'manual_disabled') {
                $result = $result->withIncrement('manual_disabled_preserved');

                continue;
            }
            if ($decision === 'manual_enabled') {
                $result = $result->withIncrement('already_enabled');
                $result = $result->withIncrement('manual_preserved');

                continue;
            }
            if ($decision === 'already_enabled') {
                $result = $result->withIncrement('already_enabled');

                continue;
            }

            $representative = $this->pickRepresentative($siblings, $targetArea, $priorities);
            if ($representative === null) {
                $result = $result->withIncrement('unsupported');

                continue;
            }

            $priority = $this->resolveBootstrapPriority(
                $userId,
                $targetArea,
                (int) $entry['default_rank'],
                $priorities,
            );
            $priorities->writeAreaMembership(
                $representative,
                $targetArea,
                true,
                $priority,
                AiModelArea::SOURCE_AUTO,
            );
            $result = $result->withIncrement('enabled', 1, $targetArea->value);
        }

        $this->bustCaches($priorities);

        return $result;
    }

    /**
     * @return list<AiModelArea>
     */
    private function mappableAreas(): array
    {
        return [
            AiModelArea::TextFast,
            AiModelArea::TextLongform,
            AiModelArea::TextReasoning,
            AiModelArea::Image,
            AiModelArea::Video,
        ];
    }

    /**
     * @param  list<SeoAiModel>  $siblings
     * @return 'manual_disabled'|'manual_enabled'|'already_enabled'|'enable'
     */
    private function decideGroup(array $siblings, AiModelArea $area, AiModelPriorityService $priorities): string
    {
        $manualEnabled = false;
        $autoEnabled = false;
        foreach ($siblings as $model) {
            if ($priorities->isExplicitlyAreaDisabled($model, $area)
                && $priorities->areaSource($model, $area) === AiModelArea::SOURCE_MANUAL) {
                return 'manual_disabled';
            }
            if ($priorities->isExplicitlyAreaEnabled($model, $area)) {
                if ($priorities->areaSource($model, $area) === AiModelArea::SOURCE_MANUAL) {
                    $manualEnabled = true;
                } else {
                    $autoEnabled = true;
                }
            }
        }
        if ($manualEnabled) {
            return 'manual_enabled';
        }
        if ($autoEnabled) {
            return 'already_enabled';
        }

        return 'enable';
    }

    /**
     * @param  list<SeoAiModel>  $siblings
     */
    private function pickRepresentative(
        array $siblings,
        AiModelArea $area,
        AiModelPriorityService $priorities,
    ): ?SeoAiModel {
        foreach ($siblings as $model) {
            if ($priorities->physicalSupportsArea($model, $area)) {
                return $model;
            }
        }

        return null;
    }

    private function resolveBootstrapPriority(
        int $userId,
        AiModelArea $area,
        int $catalogRank,
        AiModelPriorityService $priorities,
    ): int {
        $max = 0;
        $hasAny = false;
        $hasManualOrder = false;
        foreach ($priorities->areaEnabledModels($userId, $area) as $existing) {
            $hasAny = true;
            $max = max($max, $priorities->areaPriority($existing, $area, $existing->apiConnection));
            if ($priorities->areaSource($existing, $area) === AiModelArea::SOURCE_MANUAL) {
                $hasManualOrder = true;
            }
        }
        if (! $hasAny) {
            return max(1, $catalogRank);
        }
        // Existing order (manual or prior auto) stays authoritative — append only.
        unset($hasManualOrder);

        return max($max, 0) + 1;
    }

    private function bustCaches(AiModelPriorityService $priorities): void
    {
        $priorities->forgetMemo();
        try {
            $this->inventory()->forget();
        } catch (\Throwable) {
        }
        foreach ([
            AiCenterModelPresenter::class,
            AiConnectionPresenter::class,
            AiExecutionTargetPresenter::class,
        ] as $presenter) {
            try {
                if (app()->bound($presenter)) {
                    app($presenter)->forgetMemo();
                }
            } catch (\Throwable) {
            }
        }
    }
}
