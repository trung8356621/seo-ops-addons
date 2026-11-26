<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiProvisionalModelScore;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRecommendedModelAutoMapResult;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiCanonicalModelKey;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Repair / bootstrap: curated catalog first, then provisional heuristic ranking
 * for NVIDIA / Custom OpenAI-compatible providers.
 *
 * SSOT for membership remains {@see AiModelPriorityService}. Does not touch
 * ai_routing_profiles / ai_routing_targets. Does not call provider APIs.
 */
final class AiRecommendedModelMapper
{
    public const PROVISIONAL_KEY = 'omi_provisional_rank';

    public function __construct(
        private readonly AiRecommendedModelCatalog $catalog = new AiRecommendedModelCatalog(),
        private readonly ?AiModelPriorityService $priorities = null,
        private readonly ?AiModelInventory $inventory = null,
        private readonly AiTextCompatibilityAssessor $assessor = new AiTextCompatibilityAssessor(),
        private readonly AiProvisionalModelRankingPolicy $ranking = new AiProvisionalModelRankingPolicy(),
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

        /** @var array<int, array<string, mixed>> $diag */
        $diag = [];
        /** @var list<array<string, mixed>> $samples */
        $samples = [];
        /** @var array<int, list<array{model: SeoAiModel, score: AiProvisionalModelScore, area: AiModelArea}>> $heuristicPending */
        $heuristicPending = [];

        /** @var array<string, list<SeoAiModel>> $groups provider|canonical => models */
        $groups = [];
        foreach ($priorities->aiConnections($userId) as $connection) {
            if ((string) $connection->status !== 'active'
                || ! AiConnectionCredential::isUsable($connection->api_key)) {
                continue;
            }
            $cid = (int) $connection->id;
            $diag[$cid] = [
                'connection_id' => $cid,
                'provider' => (string) $connection->provider,
                'name' => (string) $connection->name,
                'discovered' => 0,
                'persisted' => 0,
                'text_compatible' => 0,
                'classified' => 0,
                'curated_matched' => 0,
                'heuristic_ranked' => 0,
                'auto_enabled' => 0,
                'unknown' => 0,
                'rejected' => 0,
                'rejected_reasons' => [],
            ];
            $models = SeoAiModel::query()
                ->where('api_connection_id', $connection->id)
                ->where('status', SeoAiModel::STATUS_ACTIVE)
                ->orderBy('id')
                ->get();
            foreach ($models as $model) {
                $model->setRelation('apiConnection', $connection);
                $diag[$cid]['discovered']++;
                $diag[$cid]['persisted']++;
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
            $connection = $siblings[0]->apiConnection;
            if (! $connection instanceof ApiConnection) {
                continue;
            }
            $cid = (int) $connection->id;
            $entry = $this->catalog->recommendedFor($canonical);

            if ($entry === null) {
                $this->collectHeuristicCandidate(
                    $userId,
                    $connection,
                    $siblings,
                    $areas,
                    $result,
                    $diag,
                    $samples,
                    $heuristicPending,
                );
                continue;
            }

            $targetArea = $entry['default_area'];
            if (! in_array($targetArea, $areas, true)) {
                continue;
            }

            $providerLogicalKey = $groupKey.'|'.$targetArea->value;
            if (isset($seenProviderLogical[$providerLogicalKey])) {
                continue;
            }
            $seenProviderLogical[$providerLogicalKey] = true;
            $result = $result->withIncrement('recognized');
            $result = $result->withIncrement('curated_matched');
            $diag[$cid]['curated_matched'] = ($diag[$cid]['curated_matched'] ?? 0) + 1;
            $diag[$cid]['classified'] = ($diag[$cid]['classified'] ?? 0) + 1;

            $decision = $this->decideGroup($siblings, $targetArea, $priorities);
            if ($decision === 'manual_disabled') {
                $result = $result->withIncrement('manual_disabled_preserved');
                $samples[] = $this->sampleRow($siblings[0], 'manual_disabled', 'curated', null);
                continue;
            }
            if ($decision === 'manual_enabled') {
                $result = $result->withIncrement('already_enabled');
                $result = $result->withIncrement('manual_preserved');
                $samples[] = $this->sampleRow($siblings[0], 'manual_preserved', 'curated', null);
                continue;
            }
            if ($decision === 'already_enabled') {
                $result = $result->withIncrement('already_enabled');
                $samples[] = $this->sampleRow($siblings[0], 'already_enabled', 'curated', null);
                continue;
            }

            $representative = $this->pickRepresentative($siblings, $targetArea, $priorities);
            if ($representative === null) {
                $result = $result->withIncrement('unsupported');
                $diag[$cid]['rejected'] = ($diag[$cid]['rejected'] ?? 0) + 1;
                $samples[] = $this->sampleRow($siblings[0], 'unsupported', 'curated', null);
                continue;
            }

            $priority = $this->resolveBootstrapPriority(
                $userId,
                $targetArea,
                (int) $entry['default_rank'],
                $priorities,
                $cid,
            );
            $this->persistCuratedProvenance($representative);
            $priorities->writeAreaMembership(
                $representative,
                $targetArea,
                true,
                $priority,
                AiModelArea::SOURCE_AUTO,
            );
            if (\Illuminate\Support\Facades\Schema::hasColumn('seo_ai_models', 'is_hidden')) {
                $representative->is_hidden = false;
                $representative->save();
            }
            $result = $result->withIncrement('enabled', 1, $targetArea->value);
            $diag[$cid]['auto_enabled'] = ($diag[$cid]['auto_enabled'] ?? 0) + 1;
            $samples[] = $this->sampleRow($representative, 'auto_mapped', 'curated', null);
        }

        // Sort heuristic candidates per connection, then append without reshuffling existing order.
        foreach ($heuristicPending as $cid => $rows) {
            $sorted = $this->ranking->sortStable($rows);
            foreach ($sorted as $row) {
                /** @var SeoAiModel $model */
                $model = $row['model'];
                /** @var AiProvisionalModelScore $score */
                $score = $row['score'];
                /** @var AiModelArea $targetArea */
                $targetArea = $row['area'];
                $connection = $model->apiConnection;
                if (! $connection instanceof ApiConnection) {
                    continue;
                }

                $decision = $this->decideGroup([$model], $targetArea, $priorities);
                if ($decision === 'manual_disabled') {
                    $result = $result->withIncrement('manual_disabled_preserved');
                    $samples[] = $this->sampleRow($model, 'manual_disabled', 'estimated', $score);
                    continue;
                }
                if ($decision === 'manual_enabled' || $decision === 'already_enabled') {
                    $result = $result->withIncrement('already_enabled');
                    if ($decision === 'manual_enabled') {
                        $result = $result->withIncrement('manual_preserved');
                    }
                    $samples[] = $this->sampleRow($model, 'already_enabled', 'estimated', $score);
                    continue;
                }

                $priority = $this->resolveBootstrapPriority($userId, $targetArea, 1000, $priorities, $cid);
                $this->persistProvisionalProvenance($model, $score);
                $priorities->writeAreaMembership(
                    $model,
                    $targetArea,
                    true,
                    $priority,
                    AiModelArea::SOURCE_AUTO,
                );
                if (\Illuminate\Support\Facades\Schema::hasColumn('seo_ai_models', 'is_hidden')) {
                    $model->is_hidden = false;
                    $model->save();
                }
                $result = $result->withIncrement('enabled', 1, $targetArea->value);
                $result = $result->withIncrement('heuristic_ranked');
                $diag[$cid]['heuristic_ranked'] = ($diag[$cid]['heuristic_ranked'] ?? 0) + 1;
                $diag[$cid]['auto_enabled'] = ($diag[$cid]['auto_enabled'] ?? 0) + 1;
                $samples[] = $this->sampleRow($model, 'auto_mapped', 'estimated', $score);
            }
        }

        $this->bustCaches($priorities);

        return $result->withDiagnostics($diag, array_slice($samples, 0, 80));
    }

    /**
     * @param  list<SeoAiModel>  $siblings
     * @param  list<AiModelArea>  $areas
     * @param  array<int, array<string, mixed>>  $diag
     * @param  list<array<string, mixed>>  $samples
     * @param  array<int, list<array{model: SeoAiModel, score: AiProvisionalModelScore, area: AiModelArea}>>  $heuristicPending
     */
    private function collectHeuristicCandidate(
        int $userId,
        ApiConnection $connection,
        array $siblings,
        array $areas,
        AiRecommendedModelAutoMapResult &$result,
        array &$diag,
        array &$samples,
        array &$heuristicPending,
    ): void {
        unset($userId);
        $cid = (int) $connection->id;
        if (! $this->assessor->allowsHeuristicAutoMap($connection)) {
            $result = $result->withIncrement('unknown');
            $diag[$cid]['unknown'] = ($diag[$cid]['unknown'] ?? 0) + 1;
            $samples[] = $this->sampleRow($siblings[0], 'unknown', 'none', null, 'not_in_curated_catalog');

            return;
        }

        $model = $siblings[0];
        $assessment = $this->assessor->assess($connection, $model);
        $diag[$cid]['classified'] = ($diag[$cid]['classified'] ?? 0) + 1;

        if (! $assessment['compatible'] || ! $assessment['area'] instanceof AiModelArea) {
            $result = $result->withIncrement('rejected');
            $diag[$cid]['rejected'] = ($diag[$cid]['rejected'] ?? 0) + 1;
            $reason = (string) $assessment['reason'];
            $diag[$cid]['rejected_reasons'][$reason] = ($diag[$cid]['rejected_reasons'][$reason] ?? 0) + 1;
            $samples[] = $this->sampleRow($model, 'rejected', 'none', null, $reason);

            return;
        }

        $diag[$cid]['text_compatible'] = ($diag[$cid]['text_compatible'] ?? 0) + 1;
        $targetArea = $assessment['area'];
        if (! in_array($targetArea, $areas, true)) {
            $result = $result->withIncrement('unknown');
            $diag[$cid]['unknown'] = ($diag[$cid]['unknown'] ?? 0) + 1;
            $samples[] = $this->sampleRow($model, 'unknown', 'estimated', null, 'area_out_of_scope');

            return;
        }

        if ((float) $assessment['confidence'] < AiTextCompatibilityAssessor::MIN_AUTO_MAP_CONFIDENCE) {
            $result = $result->withIncrement('unknown');
            $diag[$cid]['unknown'] = ($diag[$cid]['unknown'] ?? 0) + 1;
            $samples[] = $this->sampleRow($model, 'unknown', 'estimated', null, 'low_confidence');

            return;
        }

        if (! $this->priorities()->physicalSupportsArea($model, $targetArea, $connection)) {
            $result = $result->withIncrement('unsupported');
            $diag[$cid]['rejected'] = ($diag[$cid]['rejected'] ?? 0) + 1;
            $samples[] = $this->sampleRow($model, 'rejected', 'estimated', null, 'physical_unsupported');

            return;
        }

        $score = $this->ranking->score(
            $model,
            $targetArea,
            $assessment['capabilities'],
            (string) $assessment['capability'],
        );
        // Blend assessor confidence into score confidence for UI provenance.
        $score = new AiProvisionalModelScore(
            score: $score->score,
            confidence: min($score->confidence, (float) $assessment['confidence']),
            scoreSource: $score->scoreSource,
            capability: $score->capability,
            reason: $score->reason,
            missingMetadata: $score->missingMetadata,
            dimensions: $score->dimensions,
        );

        $heuristicPending[$cid][] = [
            'model' => $model,
            'score' => $score,
            'area' => $targetArea,
        ];
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
            AiModelArea::Decision,
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
        ?int $connectionId = null,
    ): int {
        $max = 0;
        $hasAny = false;
        foreach ($priorities->areaEnabledModels($userId, $area) as $existing) {
            if ($connectionId !== null && (int) $existing->api_connection_id !== $connectionId) {
                continue;
            }
            $hasAny = true;
            $max = max($max, $priorities->physicalAreaPriority($existing, $area, $existing->apiConnection));
        }
        if (! $hasAny) {
            return max(1, $catalogRank);
        }

        // Existing order (manual or prior auto) stays authoritative — append only.
        return max($max, 0) + 1;
    }

    private function persistCuratedProvenance(SeoAiModel $model): void
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $caps[self::PROVISIONAL_KEY] = [
            'score_source' => AiProvisionalModelScore::SOURCE_CURATED,
            'score' => null,
            'confidence' => 1.0,
            'reason' => 'curated_catalog',
        ];
        $model->capabilities = $caps;
        $model->save();
    }

    private function persistProvisionalProvenance(SeoAiModel $model, AiProvisionalModelScore $score): void
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $caps[self::PROVISIONAL_KEY] = $score->toArray();
        $model->capabilities = $caps;
        $model->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleRow(
        SeoAiModel $model,
        string $outcome,
        string $source,
        ?AiProvisionalModelScore $score,
        ?string $reason = null,
    ): array {
        return [
            'model_id' => (int) $model->id,
            'connection_id' => (int) $model->api_connection_id,
            'raw_model_name' => (string) $model->raw_model_name,
            'outcome' => $outcome,
            'source' => $source,
            'reason' => $reason ?? $score?->reason,
            'score' => $score?->score,
            'confidence' => $score?->confidence,
        ];
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
