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

    /** Per-language opt-in language gate on ApiConnection.metadata (no migration). */
    public const LANGUAGE_GATE_META_KEY = 'openrouter_free_language_gate';

    public const DELTA_THRESHOLD_ENV = 'FREE_POOL_REVIEW_DELTA_THRESHOLD';

    /** Presentational pool status — not a SeoAiModel DB status. */
    public const POOL_STATUS_ACTIVE = 'active';

    public const POOL_STATUS_PENDING_LANGUAGE = 'pending_language';

    public const POOL_STATUS_UNAVAILABLE = 'unavailable';

    /** Routing diagnostic when catalog exists but no language-approved runtime members. */
    public const DIAG_PENDING_LANGUAGE = 'free_pool_pending_language';

    public const DIAG_EMPTY = 'free_pool_empty';

    /** @var array<string, mixed> */
    private array $lastExpansionDiagnostics = [];

    public function __construct(
        private readonly ModelCapabilityRegistry $capabilities = new ModelCapabilityRegistry(),
        private readonly AiModelFamilyCatalog $families = new AiModelFamilyCatalog(),
        private readonly OpenRouterFreeLanguageGateService $language = new OpenRouterFreeLanguageGateService(),
        private readonly AiModelPriorityService $priorities = new AiModelPriorityService(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lastExpansionDiagnostics(): array
    {
        return $this->lastExpansionDiagnostics;
    }

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
     * Free TEXT catalog members for an area.
     * Language filter applies only when the opt-in language gate is ON.
     *
     * @return list<SeoAiModel>
     */
    public function runtimeMembers(int $userId, AiModelArea $area): array
    {
        if (! $area->isTextPrimary()) {
            return [];
        }
        $gateOn = $this->isLanguageGateEnabled($userId);
        $out = [];
        foreach ($this->catalogCandidates($userId, $area) as $row) {
            if ($gateOn && $row['language_state'] !== OpenRouterFreeLanguageState::Supported) {
                continue;
            }
            $out[] = $row['model'];
        }

        return $out;
    }

    /**
     * Pool-level language-gate flag for the user's OpenRouter Free Pool.
     * English primary is always non-blocking (returns false).
     * Enabled if any OpenRouter connection has enabled=true for the primary language.
     */
    public function isLanguageGateEnabled(int $userId, ?string $language = null): bool
    {
        if ($this->language->isEnglishPrimary()) {
            return false;
        }
        $lang = strtolower(trim((string) ($language ?? $this->language->primaryLanguage())));
        if ($lang === '' || str_starts_with($lang, 'en')) {
            return false;
        }
        foreach ($this->openRouterConnections($userId) as $connection) {
            $entry = $this->languageGateEntry($connection, $lang);
            if (! empty($entry['enabled'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enable language enforcement after a successful evaluation completes.
     */
    public function enableLanguageGate(int $userId, ?string $language = null): void
    {
        $lang = strtolower(trim((string) ($language ?? $this->language->primaryLanguage())));
        if ($lang === '' || str_starts_with($lang, 'en')) {
            return;
        }
        $now = now()->toIso8601String();
        foreach ($this->openRouterConnections($userId) as $connection) {
            $meta = is_array($connection->metadata) ? $connection->metadata : [];
            $bag = is_array($meta[self::LANGUAGE_GATE_META_KEY] ?? null)
                ? $meta[self::LANGUAGE_GATE_META_KEY]
                : [];
            $prev = is_array($bag[$lang] ?? null) ? $bag[$lang] : [];
            $bag[$lang] = [
                'enabled' => true,
                'enabled_at' => (string) ($prev['enabled_at'] ?? $now),
                'last_evaluated_at' => $now,
            ];
            $meta[self::LANGUAGE_GATE_META_KEY] = $bag;
            $this->persistConnectionMetadata($connection, $meta);
        }
    }

    /**
     * Turn language filtering off — technical Free Pool becomes runnable again.
     */
    public function disableLanguageGate(int $userId, ?string $language = null): void
    {
        $lang = strtolower(trim((string) ($language ?? $this->language->primaryLanguage())));
        if ($lang === '') {
            return;
        }
        $now = now()->toIso8601String();
        foreach ($this->openRouterConnections($userId) as $connection) {
            $meta = is_array($connection->metadata) ? $connection->metadata : [];
            $bag = is_array($meta[self::LANGUAGE_GATE_META_KEY] ?? null)
                ? $meta[self::LANGUAGE_GATE_META_KEY]
                : [];
            $prev = is_array($bag[$lang] ?? null) ? $bag[$lang] : [];
            $bag[$lang] = [
                'enabled' => false,
                'enabled_at' => $prev['enabled_at'] ?? null,
                'last_evaluated_at' => $prev['last_evaluated_at'] ?? null,
                'disabled_at' => $now,
            ];
            $meta[self::LANGUAGE_GATE_META_KEY] = $bag;
            $this->persistConnectionMetadata($connection, $meta);
        }
    }

    /**
     * Write only metadata. Inventory may set ephemeral attributes (e.g. connection_type)
     * that must not be persisted when the column is absent.
     *
     * @param  array<string, mixed>  $meta
     */
    private function persistConnectionMetadata(ApiConnection $connection, array $meta): void
    {
        $connection->setAttribute('metadata', $meta);
        ApiConnection::query()->whereKey((int) $connection->getKey())->update([
            'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
        $connection->syncOriginalAttribute('metadata');
    }

    /**
     * @return array{enabled?: bool, enabled_at?: ?string, last_evaluated_at?: ?string, disabled_at?: ?string}
     */
    private function languageGateEntry(ApiConnection $connection, string $language): array
    {
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $bag = is_array($meta[self::LANGUAGE_GATE_META_KEY] ?? null)
            ? $meta[self::LANGUAGE_GATE_META_KEY]
            : [];
        $entry = is_array($bag[$language] ?? null) ? $bag[$language] : [];

        return $entry;
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
        $gateOn = $this->isLanguageGateEnabled($userId);
        $supportedRows = [];
        $pending = 0;
        $unsupported = 0;
        foreach ($candidates as $row) {
            if ($row['language_state'] === OpenRouterFreeLanguageState::Supported) {
                $supportedRows[] = $row;
            } elseif ($row['language_state'] === OpenRouterFreeLanguageState::Pending) {
                $pending++;
            } else {
                $unsupported++;
            }
        }
        $technical = count($candidates);
        // Gate OFF: technical catalog is the runtime pool (PENDING does not block).
        // Gate ON: only SUPPORTED members are runnable.
        $memberRows = $gateOn ? $supportedRows : $candidates;
        if ($memberRows === [] && $pending === 0 && $routerAnchor === null && $technical === 0) {
            return null;
        }
        $available = count($memberRows);
        $displayCount = $gateOn ? ($available + $pending) : max($technical, $available);
        $connection = $routerAnchor?->apiConnection;
        if (! $connection instanceof ApiConnection) {
            $connections = $this->openRouterConnections($userId);
            $connection = $connections[0] ?? null;
        }
        if (! $connection instanceof ApiConnection) {
            return null;
        }
        $routerId = $routerAnchor !== null ? (int) $routerAnchor->id : 0;
        $runtimeIds = array_map(static fn (array $r): int => (int) $r['model']->id, $memberRows);
        $memberIds = $runtimeIds;
        if ($routerId > 0) {
            array_unshift($memberIds, $routerId);
        }
        $memberIds = array_values(array_unique($memberIds));
        $label = 'OpenRouter Free Pool';
        if (! $gateOn) {
            $poolStatus = $available > 0 ? self::POOL_STATUS_ACTIVE : self::POOL_STATUS_UNAVAILABLE;
            $subtitle = $this->language->isEnglishPrimary() || $pending === 0
                ? 'Auto managed · '.$available.' models'
                : $available.' models · Chưa kiểm tra ngôn ngữ';
        } elseif ($available > 0) {
            $poolStatus = self::POOL_STATUS_ACTIVE;
            $subtitle = $pending > 0
                ? $available.' khả dụng · '.$pending.' chờ đánh giá'
                : 'Auto managed · '.$available.' models';
        } elseif ($pending > 0) {
            $poolStatus = self::POOL_STATUS_PENDING_LANGUAGE;
            $subtitle = $displayCount.' model · '.$pending.' chờ đánh giá';
        } else {
            $poolStatus = self::POOL_STATUS_UNAVAILABLE;
            $subtitle = 'Auto managed · 0 models';
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
            'language_gate_enabled' => $gateOn,
            'canonical_model_key' => 'openrouter:free_pool:'.$area->value,
            'family_key' => 'openrouter.free',
            'status' => $poolStatus,
            'pool_status' => $poolStatus,
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
     * Production Free Pool never silently falls back to synthetic openrouter/free.
     * That id remains valid only for explicit infrastructure probes (Test Connection).
     *
     * @param  list<\Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate>  $candidates
     * @return list<\Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate>
     */
    public function expandFreeRouterCandidates(int $userId, AiModelArea $area, array $candidates): array
    {
        $this->lastExpansionDiagnostics = [];
        $out = [];
        $sawFreeRouter = false;
        $expandedFromPool = 0;
        foreach ($candidates as $candidate) {
            if (! OpenRouterModelEconomics::isOpenRouterFreeRouter($candidate->model)) {
                $out[] = $candidate;
                continue;
            }
            $sawFreeRouter = true;
            $counts = $this->catalogLanguageCounts($userId, $area);
            $members = $this->runtimeMembers($userId, $area);
            $reason = null;
            if ($members === []) {
                // Empty pool → zero production members (no openrouter/free bypass).
                // Pending-language block only applies when the opt-in gate is ON.
                if ($this->isLanguageGateEnabled($userId)
                    && $counts['technical'] > 0
                    && $counts['pending'] > 0
                    && $counts['supported'] === 0) {
                    $reason = self::DIAG_PENDING_LANGUAGE;
                } else {
                    $reason = self::DIAG_EMPTY;
                }
                $this->lastExpansionDiagnostics = [
                    'free_pool_reason' => $reason,
                    'language_gate_enabled' => $this->isLanguageGateEnabled($userId),
                    'free_pool_technical_candidates' => $counts['technical'],
                    'free_pool_supported' => $counts['supported'],
                    'free_pool_pending' => $counts['pending'],
                    'free_pool_unsupported' => $counts['unsupported'],
                    'free_pool_runtime_members' => 0,
                    'free_pool_expanded_candidates' => 0,
                ];
                continue;
            }
            $basePriority = $candidate->priority;
            foreach ($members as $member) {
                $exactId = (string) $member->raw_model_name;
                $out[] = new \Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate(
                    profile: $candidate->profile,
                    connection: $candidate->connection,
                    provider: $candidate->provider,
                    model: $exactId,
                    capabilities: $this->capabilities->capabilitiesFor($candidate->connection, $exactId),
                    priority: $basePriority,
                    options: $candidate->options,
                    seoAiModelId: (int) $member->id,
                    isFree: true,
                );
                $expandedFromPool++;
            }
            $this->lastExpansionDiagnostics = [
                'free_pool_reason' => null,
                'language_gate_enabled' => $this->isLanguageGateEnabled($userId),
                'free_pool_technical_candidates' => $counts['technical'],
                'free_pool_supported' => $counts['supported'],
                'free_pool_pending' => $counts['pending'],
                'free_pool_unsupported' => $counts['unsupported'],
                'free_pool_runtime_members' => count($members),
                'free_pool_expanded_candidates' => $expandedFromPool,
            ];
        }
        if ($sawFreeRouter && $this->lastExpansionDiagnostics === []) {
            $this->lastExpansionDiagnostics = [
                'free_pool_reason' => self::DIAG_EMPTY,
                'free_pool_technical_candidates' => 0,
                'free_pool_supported' => 0,
                'free_pool_pending' => 0,
                'free_pool_unsupported' => 0,
                'free_pool_runtime_members' => 0,
                'free_pool_expanded_candidates' => 0,
            ];
        }

        return $out;
    }

    /**
     * Safe Free Pool language counts (no secrets).
     *
     * @return array{technical: int, supported: int, pending: int, unsupported: int}
     */
    public function catalogLanguageCounts(int $userId, AiModelArea $area): array
    {
        $technical = 0;
        $supported = 0;
        $pending = 0;
        $unsupported = 0;
        foreach ($this->catalogCandidates($userId, $area) as $row) {
            $technical++;
            if ($row['language_state'] === OpenRouterFreeLanguageState::Supported) {
                $supported++;
            } elseif ($row['language_state'] === OpenRouterFreeLanguageState::Pending) {
                $pending++;
            } else {
                $unsupported++;
            }
        }

        return [
            'technical' => $technical,
            'supported' => $supported,
            'pending' => $pending,
            'unsupported' => $unsupported,
        ];
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
