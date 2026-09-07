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
        private readonly ?AiModelPriorityService $priorities = null,
        private readonly ?ModelCapabilityRegistry $capabilities = null,
        private readonly ?AiModelFamilyCatalog $families = null,
    ) {}

    private function priorities(): AiModelPriorityService
    {
        return $this->priorities ?? app(AiModelPriorityService::class);
    }

    private function capabilities(): ModelCapabilityRegistry
    {
        return $this->capabilities ?? app(ModelCapabilityRegistry::class);
    }

    private function families(): AiModelFamilyCatalog
    {
        return $this->families ?? new AiModelFamilyCatalog();
    }

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
        foreach ($this->priorities()->areaEnabledModels($userId, $area) as $model) {
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
            // Coverage seed is automatic — append without destroying manual order.
            $this->priorities()->appendToArea($userId, $area, [(int) $best->id]);
            $added++;
        }

        $this->priorities()->forgetMemo();

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
     * Reconcile every AI Center UI area (text + image + video when connection supports it).
     */
    public function reconcileAllAreas(int $userId): int
    {
        $total = 0;
        foreach (AiModelArea::uiCases() as $area) {
            $total += $this->reconcileArea($userId, $area);
        }

        return $total;
    }

    /**
     * Alias used by Sync All / post-sync hooks.
     */
    public function reconcileRoutingCoverage(int $userId): int
    {
        return $this->reconcileAllAreas($userId);
    }

    /**
     * @return list<ApiConnection>
     */
    private function activeAiConnections(int $userId): array
    {
        // Same visibility as Settings / AI Center inventory (workspace for owner/admin).
        return app(AiConnectionInventoryService::class)
            ->configuredAiConnections($userId)
            ->filter(static function (ApiConnection $c): bool {
                return (string) $c->status === 'active'
                    && ApiConnectionProviders::isAi((string) $c->provider);
            })
            ->sortBy(static fn (ApiConnection $c): int => (int) $c->id)
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

        $required = $area->requiredCapabilityKeys();
        // Prefer area-native capability keys (Image/Video must not fall through to text profile).
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
            if (! $this->capabilities()->satisfiesAll($connection, $key, $required)) {
                continue;
            }
            // Prefer known catalog families, then lower priority number, then lower id.
            $family = $this->families()->familyForModelId($key);
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
            AiModelArea::Image => AiExecutionProfile::ImageGeneral,
            AiModelArea::Video => AiExecutionProfile::VideoGeneral,
            default => AiExecutionProfile::TextLongform,
        };
    }
}
