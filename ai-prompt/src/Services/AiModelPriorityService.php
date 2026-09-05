<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Canonical order is per capability area (Text / Image / Video).
 * Provider metadata ranking is legacy fallback only when an area rank is unset.
 */
final class AiModelPriorityService
{
    public const STEP = 10;

    public const AREAS_KEY = 'omi_areas';

    /** @var array<string, list<SeoAiModel>> */
    private array $areaEnabledMemo = [];

    /** @var array<int, list<ApiConnection>> */
    private array $connectionsMemo = [];

    public function connectionPriority(ApiConnection $connection): int
    {
        if (Schema::hasColumn($connection->getTable(), 'routing_priority')
            && $connection->getAttribute('routing_priority') !== null) {
            return (int) $connection->getAttribute('routing_priority');
        }
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        if (isset($meta['routing_priority'])) {
            return (int) $meta['routing_priority'];
        }

        return 1_000_000 + (int) $connection->id;
    }

    public function modelPriority(SeoAiModel $model): int
    {
        $priority = (int) ($model->priority ?? 100);

        return $priority > 0 ? $priority : 100;
    }

    public function sortKey(ApiConnection $connection, SeoAiModel $model): int
    {
        return ($this->connectionPriority($connection) * 1_000_000) + $this->modelPriority($model);
    }

    /**
     * @param  list<ApiConnection>  $connections
     * @return list<ApiConnection>
     */
    public function sortConnections(array $connections): array
    {
        usort($connections, function (ApiConnection $a, ApiConnection $b): int {
            $cmp = $this->connectionPriority($a) <=> $this->connectionPriority($b);
            if ($cmp !== 0) {
                return $cmp;
            }

            return (int) $a->id <=> (int) $b->id;
        });

        return array_values($connections);
    }

    /**
     * Place a new connection after every existing ranked connection.
     */
    public function assignBottomProviderPriority(int $userId, ApiConnection $connection): void
    {
        $max = 0;
        foreach ($this->aiConnections($userId) as $row) {
            if ((int) $row->id === (int) $connection->id) {
                continue;
            }
            $max = max($max, $this->storedConnectionPriority($row) ?? 0);
        }
        $this->writeConnectionPriority($connection, $max + self::STEP);
        $this->forgetMemo();
    }

    /**
     * @param  list<int>  $orderedConnectionIds
     */
    public function reorderProviders(int $userId, array $orderedConnectionIds): void
    {
        $allowed = [];
        foreach ($this->aiConnections($userId) as $connection) {
            $allowed[(int) $connection->id] = $connection;
        }
        $rank = self::STEP;
        foreach ($orderedConnectionIds as $id) {
            $id = (int) $id;
            if (! isset($allowed[$id])) {
                continue;
            }
            $this->writeConnectionPriority($allowed[$id], $rank);
            unset($allowed[$id]);
            $rank += self::STEP;
        }
        foreach ($allowed as $connection) {
            $this->writeConnectionPriority($connection, $rank);
            $rank += self::STEP;
        }
        $this->forgetMemo();
    }

    /**
     * @param  list<int>  $orderedModelIds  representative ids (one per family row is enough)
     */
    public function reorderModels(int $userId, int $connectionId, array $orderedModelIds): void
    {
        $connection = $this->ownedConnection($userId, $connectionId);
        $models = SeoAiModel::query()
            ->where('api_connection_id', $connection->id)
            ->get()
            ->keyBy(static fn (SeoAiModel $model): int => (int) $model->id);
        $rank = self::STEP;
        $seen = [];
        foreach ($orderedModelIds as $id) {
            $id = (int) $id;
            $model = $models->get($id);
            if (! $model instanceof SeoAiModel) {
                continue;
            }
            $familyIds = $this->familyMemberIds($models->all(), $model);
            foreach ($familyIds as $memberId) {
                if (isset($seen[$memberId])) {
                    continue;
                }
                $member = $models->get($memberId);
                if ($member instanceof SeoAiModel) {
                    $member->priority = $rank;
                    $member->save();
                    $seen[$memberId] = true;
                }
            }
            $rank += self::STEP;
        }
        $this->forgetMemo();
    }

    /**
     * @param  list<int>  $ids
     */
    public function appendEnabled(int $userId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $models = SeoAiModel::query()
            ->whereIn('id', $ids)
            ->whereHas('apiConnection', function ($query) use ($userId): void {
                $query->where(function ($inner) use ($userId): void {
                    $inner->where('user_id', $userId)->orWhere('is_global', true);
                });
            })
            ->get();
        $byConnection = [];
        foreach ($models as $model) {
            $byConnection[(int) $model->api_connection_id][] = $model;
        }
        foreach ($byConnection as $connectionId => $group) {
            $max = (int) SeoAiModel::query()
                ->where('api_connection_id', $connectionId)
                ->when(
                    Schema::hasColumn('seo_ai_models', 'is_hidden'),
                    static fn ($query) => $query->where('is_hidden', false),
                )
                ->max('priority');
            $rank = max($max, 0) + self::STEP;
            foreach ($group as $model) {
                $model->priority = $rank;
                $model->save();
                $rank += self::STEP;
            }
        }
        $this->forgetMemo();
    }

    /**
     * @return list<ApiConnection>
     */
    public function aiConnections(int $userId): array
    {
        if (isset($this->connectionsMemo[$userId])) {
            return $this->connectionsMemo[$userId];
        }
        $out = [];
        foreach (ApiConnection::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('is_global', true);
            })
            ->get() as $connection) {
            if (ApiConnectionProviders::isExternal((string) $connection->provider)
                || ApiConnectionProviders::isSeo((string) $connection->provider)) {
                continue;
            }
            $out[] = $connection;
        }

        return $this->connectionsMemo[$userId] = $this->sortConnections($out);
    }

    private function storedConnectionPriority(ApiConnection $connection): ?int
    {
        if (Schema::hasColumn($connection->getTable(), 'routing_priority')
            && $connection->getAttribute('routing_priority') !== null) {
            return (int) $connection->getAttribute('routing_priority');
        }
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        if (isset($meta['routing_priority'])) {
            return (int) $meta['routing_priority'];
        }

        return null;
    }

    private function writeConnectionPriority(ApiConnection $connection, int $priority): void
    {
        $meta = is_array($connection->metadata) ? $connection->metadata : [];
        $meta['routing_priority'] = $priority;
        $connection->metadata = $meta;
        if (Schema::hasColumn($connection->getTable(), 'routing_priority')) {
            $connection->setAttribute('routing_priority', $priority);
        }
        $connection->save();
    }

    private function ownedConnection(int $userId, int $connectionId): ApiConnection
    {
        foreach ($this->aiConnections($userId) as $connection) {
            if ((int) $connection->id === $connectionId) {
                return $connection;
            }
        }

        throw new \InvalidArgumentException('AI connection not found.');
    }

    /**
     * @param  list<SeoAiModel>  $models
     * @return list<int>
     */
    private function familyMemberIds(array $models, SeoAiModel $pivot): array
    {
        $catalog = new AiModelFamilyCatalog();
        $family = $catalog->familyForModelId((string) $pivot->raw_model_name);
        if ($family === null) {
            return [(int) $pivot->id];
        }
        $ids = [];
        foreach ($models as $model) {
            $match = $catalog->familyForModelId((string) $model->raw_model_name);
            if ($match !== null && $match->familyKey === $family->familyKey) {
                $ids[] = (int) $model->id;
            }
        }

        return $ids !== [] ? $ids : [(int) $pivot->id];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    public function copyAreas(array $from, array $to): array
    {
        if (isset($from[self::AREAS_KEY]) && is_array($from[self::AREAS_KEY])) {
            $to[self::AREAS_KEY] = $from[self::AREAS_KEY];
        }
        if (isset($from[AiModelArea::PRIMARY_TYPE_KEY])) {
            $to[AiModelArea::PRIMARY_TYPE_KEY] = $from[AiModelArea::PRIMARY_TYPE_KEY];
        }
        if (isset($from[AiModelArea::PRIMARY_TYPE_SOURCE_KEY])) {
            $to[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] = $from[AiModelArea::PRIMARY_TYPE_SOURCE_KEY];
        }

        return $to;
    }

    public function areaPriority(SeoAiModel $model, AiModelArea $area, ?ApiConnection $connection = null): int
    {
        $explicit = $this->explicitAreaPriority($model, $area);
        if ($explicit !== null) {
            return $explicit;
        }
        if ($area->isTextPrimary()) {
            $legacy = $this->explicitAreaPriority($model, AiModelArea::Text);
            if ($legacy !== null) {
                return $legacy;
            }
        }
        $connection ??= $model->relationLoaded('apiConnection')
            ? $model->apiConnection
            : $model->apiConnection()->first();
        if ($connection instanceof ApiConnection) {
            return $this->sortKey($connection, $model);
        }

        return $this->modelPriority($model);
    }

    public function isAreaEnabled(SeoAiModel $model, AiModelArea $area, ApiConnection $connection): bool
    {
        $bag = $this->areaBag($model);
        $stored = $bag[$area->value] ?? null;
        if (is_array($stored) && array_key_exists('enabled', $stored)) {
            return (bool) $stored['enabled'];
        }
        if ($area->isTextPrimary()) {
            $anyNew = false;
            foreach (AiModelArea::textPrimaryCases() as $textArea) {
                $textBag = $bag[$textArea->value] ?? null;
                if (is_array($textBag) && array_key_exists('enabled', $textBag)) {
                    $anyNew = true;
                    break;
                }
            }
            if ($anyNew) {
                return false;
            }
            $legacy = $bag[AiModelArea::Text->value] ?? null;
            if (is_array($legacy) && array_key_exists('enabled', $legacy)) {
                return (bool) $legacy['enabled'];
            }
        }
        if (Schema::hasColumn('seo_ai_models', 'is_hidden') && (bool) ($model->getAttribute('is_hidden') ?? false)) {
            return false;
        }

        return $this->supportsArea($connection, (string) $model->raw_model_name, $area);
    }

    /**
     * True only when capabilities.omi_areas.{area}.enabled was written explicitly.
     */
    public function isExplicitlyAreaEnabled(SeoAiModel $model, AiModelArea $area): bool
    {
        $stored = $this->areaBag($model)[$area->value] ?? null;

        return is_array($stored) && array_key_exists('enabled', $stored) && (bool) $stored['enabled'];
    }

    /**
     * @param  list<int>  $orderedModelIds
     */
    public function reorderCapabilityModels(int $userId, AiModelArea $area, array $orderedModelIds): void
    {
        $ids = [];
        foreach ($orderedModelIds as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($ids[$id])) {
                throw new \InvalidArgumentException('Malformed model priority payload.');
            }
            $ids[$id] = $id;
        }
        $ordered = array_values($ids);
        if ($ordered === []) {
            throw new \InvalidArgumentException('Malformed model priority payload.');
        }

        $allowed = [];
        foreach ($this->areaEnabledModels($userId, $area) as $model) {
            $allowed[(int) $model->id] = true;
        }
        foreach ($ordered as $id) {
            if (! isset($allowed[$id])) {
                throw new \InvalidArgumentException('Model is not in this capability area.');
            }
        }

        DB::transaction(function () use ($userId, $area, $ordered): void {
            $this->reorderArea($userId, $area, $ordered);
        });
        $this->forgetMemo();
    }

    /**
     * @param  list<int>  $orderedModelIds
     */
    public function reorderArea(int $userId, AiModelArea $area, array $orderedModelIds): void
    {
        $pivots = $this->ownedModels($userId, $orderedModelIds);
        $connectionIds = $pivots->pluck('api_connection_id')->unique()->all();
        $all = SeoAiModel::query()
            ->whereIn('api_connection_id', $connectionIds !== [] ? $connectionIds : [0])
            ->get()
            ->keyBy(static fn (SeoAiModel $model): int => (int) $model->id);
        $rank = 1;
        $seen = [];
        foreach ($orderedModelIds as $id) {
            $model = $all->get((int) $id) ?? $pivots->get((int) $id);
            if (! $model instanceof SeoAiModel) {
                throw new \InvalidArgumentException('AI model not found.');
            }
            foreach ($this->familyMemberIds($all->all(), $model) as $memberId) {
                if (isset($seen[$memberId])) {
                    continue;
                }
                $member = $all->get($memberId);
                if ($member instanceof SeoAiModel) {
                    $this->writeAreaState($member, $area, true, $rank);
                    $seen[$memberId] = true;
                }
            }
            $rank++;
        }
    }

    /**
     * @param  list<int>  $ids
     */
    public function appendToArea(int $userId, AiModelArea $area, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $models = $this->ownedModels($userId, $ids);
        $max = 0;
        foreach ($this->areaEnabledModels($userId, $area) as $existing) {
            $max = max($max, $this->areaPriority($existing, $area, $existing->apiConnection));
        }
        $rank = max($max, 0) + 1;
        foreach ($ids as $id) {
            $model = $models->get((int) $id);
            if (! $model instanceof SeoAiModel) {
                continue;
            }
            $this->writeAreaState($model, $area, true, $rank, AiModelArea::SOURCE_MANUAL);
            $rank++;
        }
        $this->forgetMemo();
    }

    /**
     * @param  list<int>  $ids
     */
    public function removeFromArea(int $userId, AiModelArea $area, array $ids): void
    {
        foreach ($this->ownedModels($userId, $ids) as $model) {
            $this->writeAreaState(
                $model,
                $area,
                false,
                $this->explicitAreaPriority($model, $area) ?? $this->modelPriority($model),
                AiModelArea::SOURCE_MANUAL,
            );
        }
        $this->forgetMemo();
    }

    /**
     * @return list<SeoAiModel>
     */
    public function areaEnabledModels(int $userId, AiModelArea $area): array
    {
        $memoKey = $userId.'|'.$area->value;
        if (isset($this->areaEnabledMemo[$memoKey])) {
            return $this->areaEnabledMemo[$memoKey];
        }
        $out = [];
        foreach ($this->aiConnections($userId) as $connection) {
            $models = SeoAiModel::query()
                ->where('api_connection_id', $connection->id)
                ->where('status', SeoAiModel::STATUS_ACTIVE)
                ->get();
            foreach ($models as $model) {
                $model->setRelation('apiConnection', $connection);
                if (! $this->isAreaEnabled($model, $area, $connection)) {
                    continue;
                }
                $stored = $this->areaBag($model)[$area->value] ?? null;
                $explicitlyEnabled = is_array($stored) && array_key_exists('enabled', $stored) && (bool) $stored['enabled'];
                if (! $explicitlyEnabled && ! $this->supportsArea($connection, (string) $model->raw_model_name, $area)) {
                    continue;
                }
                // Unknown models must be explicitly opted into an area (Add from picker).
                $family = (new AiModelFamilyCatalog())->familyForModelId((string) $model->raw_model_name);
                if ($family === null && ! $explicitlyEnabled) {
                    continue;
                }
                $out[] = $model;
            }
        }
        usort($out, function (SeoAiModel $a, SeoAiModel $b) use ($area): int {
            $cmp = $this->areaPriority($a, $area, $a->apiConnection)
                <=> $this->areaPriority($b, $area, $b->apiConnection);
            if ($cmp !== 0) {
                return $cmp;
            }

            return (int) $a->id <=> (int) $b->id;
        });

        return $this->areaEnabledMemo[$memoKey] = array_values($out);
    }

    public function forgetMemo(): void
    {
        $this->areaEnabledMemo = [];
        $this->connectionsMemo = [];
    }

    private function supportsArea(ApiConnection $connection, string $modelKey, AiModelArea $area): bool
    {
        $registry = new ModelCapabilityRegistry();
        foreach ($area->requiredCapabilityKeys() as $capability) {
            if ($registry->supports($connection, $modelKey, $capability)) {
                return true;
            }
        }
        $family = (new AiModelFamilyCatalog())->familyForModelId($modelKey);
        if ($family === null) {
            return false;
        }

        return match ($area) {
            AiModelArea::Text,
            AiModelArea::TextFast,
            AiModelArea::TextLongform,
            AiModelArea::TextReasoning => in_array($family->modality, ['text', 'multimodal'], true),
            AiModelArea::Image => in_array($family->modality, ['image', 'multimodal'], true),
            AiModelArea::Video => in_array($family->modality, ['video', 'multimodal'], true),
        };
    }

    private function writeAreaState(
        SeoAiModel $model,
        AiModelArea $area,
        bool $enabled,
        int $priority,
        ?string $source = null,
    ): void {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $areas = is_array($caps[self::AREAS_KEY] ?? null) ? $caps[self::AREAS_KEY] : [];
        $current = is_array($areas[$area->value] ?? null) ? $areas[$area->value] : [];
        $resolvedSource = $source ?? (isset($current['source']) ? (string) $current['source'] : '');
        $areas[$area->value] = array_filter([
            'enabled' => $enabled,
            'priority' => $priority,
            'source' => $resolvedSource !== '' ? $resolvedSource : null,
        ], static fn (mixed $value): bool => $value !== null);
        // Text models may belong to multiple text capability areas (Fast / Longform /
        // Reasoning). PROFILE_MODELS and Models UI order are the SSOT — enabling one
        // area must NOT silently disable the others (that collapsed Reasoning to 1 candidate).
        if ($enabled && $area->isTextPrimary() && $source === AiModelArea::SOURCE_MANUAL) {
            $caps[AiModelArea::PRIMARY_TYPE_KEY] = $area->value;
            $caps[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] = AiModelArea::SOURCE_MANUAL;
        }
        $caps[self::AREAS_KEY] = $areas;
        $model->capabilities = $caps;
        if ($enabled && Schema::hasColumn('seo_ai_models', 'is_hidden')) {
            $model->is_hidden = false;
        } elseif (! $enabled && Schema::hasColumn('seo_ai_models', 'is_hidden') && ! $this->anyAreaEnabled($areas, $area)) {
            $model->is_hidden = true;
        }
        $model->save();
    }

    /**
     * @param  array<string, mixed>  $areas
     */
    private function anyAreaEnabled(array $areas, AiModelArea $except): bool
    {
        foreach (AiModelArea::cases() as $area) {
            if ($area === $except) {
                continue;
            }
            if (! empty($areas[$area->value]['enabled'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function areaBag(SeoAiModel $model): array
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];

        return is_array($caps[self::AREAS_KEY] ?? null) ? $caps[self::AREAS_KEY] : [];
    }

    private function explicitAreaPriority(SeoAiModel $model, AiModelArea $area): ?int
    {
        $stored = $this->areaBag($model)[$area->value] ?? null;
        if (! is_array($stored) || ! isset($stored['priority'])) {
            return null;
        }

        return (int) $stored['priority'];
    }

    /**
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, SeoAiModel>
     */
    private function ownedModels(int $userId, array $ids): \Illuminate\Support\Collection
    {
        return SeoAiModel::query()
            ->with('apiConnection')
            ->whereIn('id', $ids)
            ->whereHas('apiConnection', function ($query) use ($userId): void {
                $query->where(function ($inner) use ($userId): void {
                    $inner->where('user_id', $userId)->orWhere('is_global', true);
                });
            })
            ->get()
            ->keyBy(static fn (SeoAiModel $model): int => (int) $model->id);
    }
}
