<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\OpenRouterFreeLanguageState;

/**
 * Catalog-driven OpenRouter Free Pool for text areas only.
 * Virtual UI row — never invents a fake SeoAiModel named "OpenRouter Free Pool".
 */
final class OpenRouterFreePoolService
{
    public const ROW_TYPE = 'free_pool';

    public const SNAPSHOT_META_KEY = 'openrouter_free_catalog_snapshot';

    public const EVAL_SNAPSHOT_META_KEY = 'openrouter_free_language_eval_snapshot';

    public const DELTA_THRESHOLD_ENV = 'FREE_POOL_REVIEW_DELTA_THRESHOLD';

    public function __construct(
        private readonly ModelCapabilityRegistry $capabilities = new ModelCapabilityRegistry(),
        private readonly AiModelFamilyCatalog $families = new AiModelFamilyCatalog(),
        private readonly OpenRouterFreeLanguageGateService $language = new OpenRouterFreeLanguageGateService(),
        private readonly AiModelPriorityService $priorities = new AiModelPriorityService(),
    ) {}

    public function deltaThreshold(): int
    {
        $raw = getenv(self::DELTA_THRESHOLD_ENV);
        if ($raw === false || $raw === '') {
            $raw = (string) (env(self::DELTA_THRESHOLD_ENV, 10) ?? 10);
        }

        return max(1, (int) $raw);
    }

    /**
     * @return list<ApiConnection>
     */
    public function openRouterConnections(int $userId): array
    {
        return array_values(array_filter(
            $this->priorities->aiConnections($userId),
            static fn (ApiConnection $c): bool => (string) $c->provider === ApiConnectionProviders::OPENROUTER
                && (string) $c->status === 'active',
        ));
    }

    /**
     * Free TEXT catalog members for an area (language-filtered for runtime).
     *
     * @return list<SeoAiModel>
     */
    public function runtimeMembers(int $userId, AiModelArea $area): array
    {
        if (! $area->isTextPrimary()) {
            return [];
        }
        $out = [];
        foreach ($this->catalogCandidates($userId, $area) as $row) {
            if ($row['language_state'] !== OpenRouterFreeLanguageState::Supported) {
                continue;
            }
            $out[] = $row['model'];
        }

        return $out;
    }

    /**
     * @return list<array{
     *   model: SeoAiModel,
     *   language_state: OpenRouterFreeLanguageState,
     *   rank_score: float,
     *   provider_model_id: string
     * }>
     */
    public function catalogCandidates(int $userId, AiModelArea $area): array
    {
        if (! $area->isTextPrimary()) {
            return [];
        }
        $rows = [];
        foreach ($this->openRouterConnections($userId) as $connection) {
            $models = SeoAiModel::query()
                ->where('api_connection_id', (int) $connection->id)
                ->where('status', SeoAiModel::STATUS_ACTIVE)
                ->orderBy('id')
                ->get();
            foreach ($models as $model) {
                $raw = (string) $model->raw_model_name;
                if (OpenRouterModelEconomics::isOpenRouterFreeRouter($raw)) {
                    continue;
                }
                $caps = is_array($model->capabilities) ? $model->capabilities : [];
                if (! OpenRouterModelEconomics::modelIsFree($model)
                    && ! OpenRouterModelEconomics::isFree($caps, $raw)) {
                    continue;
                }
                if (! OpenRouterModelEconomics::isChatTextModel($caps, $raw)) {
                    continue;
                }
                if (! $this->supportsArea($connection, $raw, $area)) {
                    continue;
                }
                if (! $this->primaryTypeFitsArea($model, $area)) {
                    continue;
                }
                $this->language->ensurePendingIfMissing($model);
                $model->refresh();
                $rows[] = [
                    'model' => $model,
                    'language_state' => $this->language->effectiveState($model),
                    'rank_score' => $this->openRouterRankScore($model),
                    'provider_model_id' => $raw,
                ];
            }
        }
        usort($rows, static function (array $a, array $b): int {
            $cmp = $b['rank_score'] <=> $a['rank_score'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['provider_model_id'], $b['provider_model_id']);
        });

        return $rows;
    }

    /**
     * Virtual Free Pool view-model for AI Center. Null when no free catalog members.
     *
     * @return array<string, mixed>|null
     */
    public function presentPoolRow(int $userId, AiModelArea $area, ?SeoAiModel $routerAnchor = null): ?array
    {
        if (! $area->isTextPrimary()) {
            return null;
        }
        $candidates = $this->catalogCandidates($userId, $area);
        if ($candidates === [] && $routerAnchor === null) {
            return null;
        }
        $runtime = [];
        $pending = 0;
        $unsupported = 0;
        foreach ($candidates as $row) {
            if ($row['language_state'] === OpenRouterFreeLanguageState::Supported) {
                $runtime[] = $row;
            } elseif ($row['language_state'] === OpenRouterFreeLanguageState::Pending) {
                $pending++;
            } else {
                $unsupported++;
            }
        }
        // English (or all supported): pool visible when catalog has members.
        // Non-English with only PENDING: still show pool with pending diagnostics.
        if ($runtime === [] && $pending === 0 && $routerAnchor === null) {
            return null;
        }
        $memberCount = count($runtime);
        // UI "N models" includes pending candidates so the pool is never an empty shell.
        $displayCount = $memberCount + $pending;
        $connection = $routerAnchor?->apiConnection;
        if (! $connection instanceof ApiConnection) {
            $connections = $this->openRouterConnections($userId);
            $connection = $connections[0] ?? null;
        }
        if (! $connection instanceof ApiConnection) {
            return null;
        }
        $routerId = $routerAnchor !== null ? (int) $routerAnchor->id : 0;
        $memberIds = array_map(static fn (array $r): int => (int) $r['model']->id, $runtime);
        if ($routerId > 0) {
            array_unshift($memberIds, $routerId);
        }
        $memberIds = array_values(array_unique($memberIds));
        $available = $memberCount;
        $label = 'OpenRouter Free Pool';
        $subtitle = 'Auto managed · '.$displayCount.' models';
        if ($pending > 0) {
            $subtitle .= ' · '.$pending.' pending language';
        }

        return [
            'row_type' => self::ROW_TYPE,
            'identity' => 'free_pool|'.$area->value.'|'.(int) $connection->id,
            'label' => $label,
            'model_name' => $label,
            'full_label' => $label.' · '.$subtitle,
            'provider' => 'OpenRouter',
            'provider_key' => ApiConnectionProviders::OPENROUTER,
            'connection_id' => (int) $connection->id,
            'ids' => $memberIds !== [] ? $memberIds : ($routerId > 0 ? [$routerId] : []),
            'area_priority' => $routerAnchor !== null
                ? $this->priorities->areaPriority($routerAnchor, $area, $connection)
                : 9999,
            'is_free' => true,
            'is_free_pool' => true,
            'free_pool_count' => $displayCount,
            'member_count' => $displayCount,
            'available_count' => $available,
            'healthy_count' => $available,
            'cooldown_count' => 0,
            'pending_language_count' => $pending,
            'unsupported_language_count' => $unsupported,
            'canonical_model_key' => 'openrouter:free_pool:'.$area->value,
            'family_key' => 'openrouter.free',
            'status' => SeoAiModel::STATUS_ACTIVE,
            'routes' => [[
                'connection_id' => (int) $connection->id,
                'provider_key' => ApiConnectionProviders::OPENROUTER,
                'provider' => 'OpenRouter',
                'is_aggregator' => true,
                'ids' => $memberIds,
                'short_code' => 'OR',
                'badge_variant' => 'free',
            ]],
            'short_code' => 'OR',
            'badge_variant' => 'free',
            'source' => 'openrouter',
            'subtitle' => $subtitle,
            'unknown' => false,
            'visible' => true,
        ];
    }

    /**
     * Ensure synthetic free-router anchor is enabled in each text area so the pool is draggable.
     */
    public function ensureRouterAnchors(int $userId): void
    {
        foreach ($this->openRouterConnections($userId) as $connection) {
            $router = SeoAiModel::query()
                ->where('api_connection_id', (int) $connection->id)
                ->where('raw_model_name', OpenRouterModelEconomics::FREE_ROUTER_ID)
                ->first();
            if (! $router instanceof SeoAiModel) {
                continue;
            }
            $router->is_hidden = false;
            $router->status = SeoAiModel::STATUS_ACTIVE;
            $router->display_name = 'OpenRouter Free Pool';
            $router->save();
            foreach (AiModelArea::textPrimaryCases() as $area) {
                if ($this->priorities->isExplicitlyAreaEnabled($router, $area)) {
                    continue;
                }
                // Only auto-enable when catalog has free members for the area.
                if ($this->catalogCandidates($userId, $area) === []) {
                    continue;
                }
                $this->priorities->appendToArea($userId, $area, [(int) $router->id]);
            }
        }
        $this->priorities->forgetMemo();
    }

    public function findRouterAnchor(int $userId, AiModelArea $area): ?SeoAiModel
    {
        foreach ($this->priorities->areaEnabledModels($userId, $area) as $model) {
            if (OpenRouterModelEconomics::isOpenRouterFreeRouter((string) $model->raw_model_name)) {
                return $model;
            }
        }
        foreach ($this->openRouterConnections($userId) as $connection) {
            $router = SeoAiModel::query()
                ->where('api_connection_id', (int) $connection->id)
                ->where('raw_model_name', OpenRouterModelEconomics::FREE_ROUTER_ID)
                ->first();
            if ($router instanceof SeoAiModel) {
                return $router;
            }
        }

        return null;
    }

    /**
     * Expand free-router candidate into ranked language-approved free members.
     *
     * @param  list<\Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate>  $candidates
     * @return list<\Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate>
     */
    public function expandFreeRouterCandidates(int $userId, AiModelArea $area, array $candidates): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            if (! OpenRouterModelEconomics::isOpenRouterFreeRouter($candidate->model)) {
                $out[] = $candidate;
                continue;
            }
            $members = $this->runtimeMembers($userId, $area);
            if ($members === []) {
                // Keep router itself as last-resort OpenRouter free endpoint.
                $out[] = $candidate;
                continue;
            }
            $basePriority = $candidate->priority;
            $i = 0;
            foreach ($members as $member) {
                $out[] = new \Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate(
                    profile: $candidate->profile,
                    connection: $candidate->connection,
                    provider: $candidate->provider,
                    model: (string) $member->raw_model_name,
                    capabilities: $this->capabilities->capabilitiesFor($candidate->connection, (string) $member->raw_model_name),
                    priority: $basePriority + ($i * 0.0001),
                    options: $candidate->options,
                    seoAiModelId: (int) $member->id,
                    isFree: true,
                );
                $i++;
            }
        }

        return $out;
    }

    /**
     * Compare free TEXT catalog fingerprints; store snapshot; return delta summary.
     *
     * @return array{added: list<string>, removed: list<string>, material_count: int, needs_review: bool}
     */
    public function refreshCatalogSnapshot(ApiConnection $connection): array
    {
        $ids = [];
        $models = SeoAiModel::query()
            ->where('api_connection_id', (int) $connection->id)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->get();
        foreach ($models as $model) {
            $raw = (string) $model->raw_model_name;
            $caps = is_array($model->capabilities) ? $model->capabilities : [];
            if (OpenRouterModelEconomics::isOpenRouterFreeRouter($raw)) {
                continue;
            }
            if (! OpenRouterModelEconomics::isFree($caps, $raw) && ! OpenRouterModelEconomics::modelIsFree($model)) {
                continue;
            }
            if (! OpenRouterModelEconomics::isChatTextModel($caps, $raw)) {
                continue;
            }
            $ids[] = $raw;
            $this->language->ensurePendingIfMissing($model);
        }
        sort($ids);
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $previous = is_array($meta[self::SNAPSHOT_META_KEY] ?? null)
            ? array_values(array_map('strval', $meta[self::SNAPSHOT_META_KEY]['ids'] ?? []))
            : [];
        $added = array_values(array_diff($ids, $previous));
        $removed = array_values(array_diff($previous, $ids));
        $material = count($added) + count($removed);
        $meta[self::SNAPSHOT_META_KEY] = [
            'ids' => $ids,
            'updated_at' => now()->toIso8601String(),
            'last_delta' => ['added' => $added, 'removed' => $removed],
        ];
        $evalSnap = is_array($meta[self::EVAL_SNAPSHOT_META_KEY] ?? null)
            ? $meta[self::EVAL_SNAPSHOT_META_KEY]
            : ['ids' => [], 'updated_at' => null];
        $unevaluated = array_values(array_diff($ids, array_map('strval', $evalSnap['ids'] ?? [])));
        $accumulated = count($unevaluated);
        $meta['openrouter_free_review_banner'] = [
            'needs_review' => $accumulated >= $this->deltaThreshold(),
            'added_since_eval' => count($unevaluated),
            'removed_since_last_sync' => count($removed),
        ];
        $connection->metadata = $meta;
        $connection->save();

        return [
            'added' => $added,
            'removed' => $removed,
            'material_count' => $material,
            'needs_review' => $accumulated >= $this->deltaThreshold(),
        ];
    }

    public function markLanguageEvalSnapshot(ApiConnection $connection): void
    {
        $snap = is_array($connection->metadata[self::SNAPSHOT_META_KEY] ?? null)
            ? $connection->metadata[self::SNAPSHOT_META_KEY]
            : ['ids' => []];
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $meta[self::EVAL_SNAPSHOT_META_KEY] = [
            'ids' => array_values(array_map('strval', $snap['ids'] ?? [])),
            'updated_at' => now()->toIso8601String(),
        ];
        $meta['openrouter_free_review_banner'] = [
            'needs_review' => false,
            'added_since_eval' => 0,
            'removed_since_last_sync' => 0,
        ];
        $connection->metadata = $meta;
        $connection->save();
    }

    /**
     * Models pending manual language evaluation (non-English only).
     *
     * @return list<SeoAiModel>
     */
    public function pendingLanguageModels(int $userId, AiModelArea $area): array
    {
        if ($this->language->isEnglishPrimary()) {
            return [];
        }
        $out = [];
        foreach ($this->catalogCandidates($userId, $area) as $row) {
            if ($row['language_state'] === OpenRouterFreeLanguageState::Pending) {
                $out[] = $row['model'];
            }
        }

        return $out;
    }

    private function supportsArea(ApiConnection $connection, string $raw, AiModelArea $area): bool
    {
        foreach ($area->requiredCapabilityKeys() as $capability) {
            if ($this->capabilities->supports($connection, $raw, $capability)) {
                return true;
            }
        }
        // Free chat text defaults to text.generate when catalog lacks resolved caps.
        if (in_array($area, AiModelArea::textPrimaryCases(), true)) {
            return $this->capabilities->supports($connection, $raw, AiModelCapability::TextGenerate->value)
                || OpenRouterModelEconomics::isChatTextModel(
                    [],
                    $raw,
                );
        }

        return false;
    }

    private function primaryTypeFitsArea(SeoAiModel $model, AiModelArea $area): bool
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $primary = (string) ($caps[AiModelArea::PRIMARY_TYPE_KEY] ?? '');
        if ($primary === '') {
            return true;
        }
        // Soft fit: allow model in its primary area; also allow Longform members in Reasoning
        // when they expose reasoning capability (handled by supportsArea).
        if ($primary === $area->value) {
            return true;
        }
        // Free router / unclassified spill: include in every text tab.
        if ($primary === AiModelArea::Text->value) {
            return true;
        }

        // Cross-list carefully so Fast-only models don't flood Reasoning.
        return match ($area) {
            AiModelArea::TextFast => $primary === AiModelArea::TextFast->value,
            AiModelArea::TextLongform => in_array($primary, [
                AiModelArea::TextLongform->value,
                AiModelArea::TextFast->value,
            ], true),
            AiModelArea::TextReasoning => in_array($primary, [
                AiModelArea::TextReasoning->value,
                AiModelArea::TextLongform->value,
            ], true),
            default => false,
        };
    }

    /**
     * Higher is better. Uses OpenRouter metadata only — never an LLM score.
     */
    private function openRouterRankScore(SeoAiModel $model): float
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $meta = OpenRouterModelEconomics::pricingBag($caps) !== []
            || isset($caps['provider_metadata'])
            ? (is_array($caps['provider_metadata'] ?? null) ? $caps['provider_metadata'] : [])
            : [];
        $context = (float) ($meta['context_length'] ?? 0);
        $family = $this->families->familyForModelId((string) $model->raw_model_name);
        $quality = (float) ($family?->qualityTier ?? 0);
        $speed = (float) ($family?->speedTier ?? 0);

        // Prefer larger context + catalog quality/speed hints; :free suffix models without family still rank by context.
        return ($context / 1000.0) + ($quality * 100.0) + ($speed * 10.0);
    }
}
