<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiConnectionCredential;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Ensures each active, capable API connection has ≥1 eligible route in an area profile.
 * Appends auto-coverage at the end — does not reorder manual priorities.
 */
final class AiConnectionCoverageService
{
    public function __construct(
        private readonly AiModelPriorityService $priorities,
        private readonly ModelCapabilityRegistry $capabilities,
        private readonly ?AiModelFamilyCatalog $families = null,
    ) {}

    /**
     * @return list<array{
     *   connection_id: int,
     *   provider: string,
     *   name: string,
     *   covered: bool,
     *   supported: bool,
     *   reason: string|null,
     *   auto_added_model_id: int|null
     * }>
     */
    public function coverageReport(int $userId, AiModelArea $area): array
    {
        $profile = $this->profileForArea($area);
        $enabledIds = [];
        foreach ($this->priorities->areaEnabledModels($userId, $area) as $model) {
            $cid = (int) ($model->api_connection_id ?? 0);
            if ($cid > 0) {
                $enabledIds[$cid] = true;
            }
        }

        $out = [];
        foreach ($this->activeAiConnections($userId) as $connection) {
            $cid = (int) $connection->id;
            $best = $this->bestEligibleModel($connection, $area, $profile);
            $supported = $best !== null;
            $covered = isset($enabledIds[$cid]);
            $out[] = [
                'connection_id' => $cid,
                'provider' => (string) $connection->provider,
                'name' => (string) $connection->name,
                'covered' => $covered,
                'supported' => $supported,
                'reason' => match (true) {
                    ! AiConnectionCredential::isUsable($connection->api_key) => 'missing_credentials',
                    ! $supported => 'no_compatible_model',
                    ! $covered => 'missing_route',
                    default => null,
                },
                'auto_added_model_id' => null,
            ];
        }

        return $out;
    }

    /**
     * Append one best model per uncovered supported connection. Returns number of models added.
     */
    public function reconcileArea(int $userId, AiModelArea $area): int
    {
        $profile = $this->profileForArea($area);
        $added = 0;
        $report = $this->coverageReport($userId, $area);
        foreach ($report as $row) {
            if ($row['covered'] || ! $row['supported'] || $row['reason'] === 'missing_credentials') {
                continue;
            }
            $connection = ApiConnection::query()->find($row['connection_id']);
            if (! $connection instanceof ApiConnection) {
                continue;
            }
            $best = $this->bestEligibleModel($connection, $area, $profile);
            if ($best === null) {
                continue;
            }
            $this->priorities->appendToArea($userId, $area, [(int) $best->id]);
            $added++;
        }

        return $added;
    }

    /**
     * Reconcile all UI text areas for a user (e.g. after connection create/enable).
     */
    public function reconcileAllTextAreas(int $userId): int
    {
        $total = 0;
        foreach (AiModelArea::textPrimaryCases() as $area) {
            $total += $this->reconcileArea($userId, $area);
        }

        return $total;
    }

    /**
     * @return list<ApiConnection>
     */
    private function activeAiConnections(int $userId): array
    {
        return ApiConnection::query()
            ->where(function ($q) use ($userId): void {
                $q->where('user_id', $userId)->orWhere('is_global', true);
            })
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->filter(static fn (ApiConnection $c): bool => ApiConnectionProviders::isAi((string) $c->provider))
            ->values()
            ->all();
    }

    private function bestEligibleModel(
        ApiConnection $connection,
        AiModelArea $area,
        AiExecutionProfile $profile,
    ): ?SeoAiModel {
        if (! AiConnectionCredential::isUsable($connection->api_key)) {
            return null;
        }

        $required = $profile->requiredCapabilityKeys();
        $models = SeoAiModel::query()
            ->where('api_connection_id', $connection->id)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($models as $model) {
            $key = (string) $model->raw_model_name;
            if (! $this->capabilities->satisfiesAll($connection, $key, $required)) {
                continue;
            }
            // Prefer known catalog families, then lower priority number, then lower id.
            $family = ($this->families ?? new AiModelFamilyCatalog())->familyForModelId($key);
            $score = ($family === null ? 1_000_000 : 0)
                + (int) $model->priority * 1000
                + (int) $model->id;
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $model;
            }
        }

        return $best;
    }

    private function profileForArea(AiModelArea $area): AiExecutionProfile
    {
        return match ($area) {
            AiModelArea::TextFast => AiExecutionProfile::TextFast,
            AiModelArea::TextLongform => AiExecutionProfile::TextLongform,
            AiModelArea::TextReasoning => AiExecutionProfile::TextReasoning,
            default => AiExecutionProfile::TextLongform,
        };
    }
}
