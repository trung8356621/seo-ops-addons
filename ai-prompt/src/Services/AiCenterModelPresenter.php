<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

final class AiCenterModelPresenter
{
    public function __construct(
        private readonly AiModelFamilyCatalog $families = new AiModelFamilyCatalog(),
        private readonly \Omnichannel\Addons\AiPrompt\Support\AiModelLabelPresenter $labels = new \Omnichannel\Addons\AiPrompt\Support\AiModelLabelPresenter(),
        private readonly AiModelPriorityService $priorities = new AiModelPriorityService(),
        private readonly ModelCapabilityRegistry $capabilities = new ModelCapabilityRegistry(),
        private readonly AiExecutionTargetPresenter $executionLabels = new AiExecutionTargetPresenter(),
    ) {}

    /** @var array<string, list<array<string, mixed>>> */
    private array $inventoryMemo = [];

    /** @var array<int, SeoAiModel> */
    private array $modelsById = [];

    /** @var array<int, ApiConnection> */
    private array $connectionsById = [];

    public function forgetMemo(): void
    {
        $this->inventoryMemo = [];
        $this->modelsById = [];
        $this->connectionsById = [];
        $this->priorities->forgetMemo();
        $this->executionLabels->forgetMemo();
    }

    /**
     * Unified family rows for the Models table. Unknown models appear only when enabled
     * (or when technical/show-hidden is on).
     *
     * @param  array{search?: string, provider?: string, type?: string, status?: string, show_hidden?: bool, technical?: bool}  $filters
     * @return list<array<string, mixed>>
     */
    public function tableRows(int $userId, array $filters = []): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $provider = trim((string) ($filters['provider'] ?? ''));
        $type = trim((string) ($filters['type'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $showHidden = (bool) ($filters['show_hidden'] ?? false);
        $technical = (bool) ($filters['technical'] ?? false);

        $rows = [];
        foreach ($this->familyInventory($userId, enabledOnly: ! $showHidden && ! $technical) as $row) {
            if (! $showHidden && ($row['visible'] ?? true) === false && ! $technical) {
                continue;
            }
            if (! $technical && ($row['unknown'] ?? false) === true && ($row['visible'] ?? false) !== true) {
                continue;
            }
            if ($provider !== '' && $provider !== 'all' && (string) $row['provider_key'] !== $provider) {
                continue;
            }
            if ($type !== '' && $type !== 'all' && (string) $row['type'] !== $type) {
                continue;
            }
            if ($status !== '' && $status !== 'all' && (string) $row['status'] !== $status) {
                continue;
            }
            if ($search !== '') {
                $hay = mb_strtolower((string) $row['provider'].' '.(string) $row['label'].' '.(string) ($row['family_key'] ?? ''));
                if (! str_contains($hay, $search)) {
                    continue;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Flat capability-area table. Provider is a source column, not a group.
     *
     * @param  array{search?: string, provider?: string, status?: string, technical?: bool}  $filters
     * @return list<array<string, mixed>>
     */
    public function areaRows(int $userId, AiModelArea $area, array $filters = []): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $provider = trim((string) ($filters['provider'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $technical = (bool) ($filters['technical'] ?? false);
        $cost = trim((string) ($filters['cost'] ?? 'all'));
        $rows = [];
        foreach ($this->familyInventory($userId, enabledOnly: false) as $row) {
            $connection = $this->connectionById($userId, (int) ($row['connection_id'] ?? 0));
            $model = $this->firstModel($row);
            if ($connection === null || $model === null) {
                continue;
            }
            $unknown = (bool) ($row['unknown'] ?? false);
            $enabled = $this->priorities->isAreaEnabled($model, $area, $connection);
            // Unknown inventory is opt-in: only show after an explicit Add (omi_areas flag),
            // unless Technical models is on.
            if ($unknown && ! $technical && ! $this->priorities->isExplicitlyAreaEnabled($model, $area)) {
                continue;
            }
            if (! $enabled) {
                continue;
            }
            if (! $unknown && ! $this->rowSupportsArea($connection, $row, $area)) {
                continue;
            }
            if ($unknown && ! $this->unknownRowMatchesArea($connection, $row, $area)) {
                continue;
            }
            if ($provider !== '' && $provider !== 'all' && (string) $row['provider_key'] !== $provider) {
                continue;
            }
            if ($status !== '' && $status !== 'all' && (string) $row['status'] !== $status) {
                continue;
            }
            if ($search !== '') {
                $hay = mb_strtolower((string) $row['label'].' '.(string) $row['provider'].' '.(string) ($row['family_key'] ?? ''));
                if (! str_contains($hay, $search)) {
                    continue;
                }
            }
            $presented = $this->executionLabels->presentNamed(
                $connection,
                (string) $row['label'],
                $userId,
            );
            $row['source'] = (string) $row['provider'];
            $row['short_code'] = $presented['short_code'];
            $row['badge_variant'] = $presented['badge_variant'];
            $row['model_name'] = $presented['model_name'];
            $row['full_label'] = $presented['full_label'];
            $row['area_priority'] = $this->priorities->areaPriority($model, $area, $connection);
            $row['is_free'] = OpenRouterModelEconomics::modelIsFree($model)
                || OpenRouterModelEconomics::isFree([], (string) $model->raw_model_name);
            if ($cost === 'free' && ! $row['is_free']) {
                continue;
            }
            if ($cost === 'paid' && $row['is_free']) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => ((int) ($a['area_priority'] ?? 0)) <=> ((int) ($b['area_priority'] ?? 0)));

        return array_values($rows);
    }

    /**
     * @return array<string, array{enabled: int, available: int}>
     */
    public function areaCounts(int $userId): array
    {
        $out = [];
        foreach (AiModelArea::uiCases() as $area) {
            $out[$area->value] = ['enabled' => 0, 'available' => 0];
        }
        foreach ($this->familyInventory($userId, enabledOnly: false) as $row) {
            if (($row['unknown'] ?? false) === true) {
                continue;
            }
            $connection = $this->connectionById($userId, (int) ($row['connection_id'] ?? 0));
            $model = $this->firstModel($row);
            if ($connection === null || $model === null) {
                continue;
            }
            foreach (AiModelArea::uiCases() as $area) {
                if (! $this->rowSupportsArea($connection, $row, $area)) {
                    continue;
                }
                if ($this->priorities->isAreaEnabled($model, $area, $connection)) {
                    $out[$area->value]['enabled']++;
                } else {
                    $out[$area->value]['available']++;
                }
            }
        }

        return $out;
    }

    public function shortFamilyLabel(string $label, string $providerLabel): string
    {
        $prefixes = [$providerLabel];
        if (str_contains($providerLabel, ' ')) {
            $parts = explode(' ', $providerLabel);
            $last = (string) end($parts);
            if (mb_strlen($last) > 2) {
                $prefixes[] = $last;
            }
        }
        usort($prefixes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($label, $prefix.' ')) {
                $short = trim(mb_substr($label, mb_strlen($prefix)));
                if ($short !== '') {
                    return $short;
                }
            }
        }

        return $label;
    }

    /**
     * @return array{discovered: int, enabled: int, available: int}
     */
    public function counts(int $userId): array
    {
        $connectionIds = $this->aiConnectionIds($userId);
        if ($connectionIds === []) {
            return ['discovered' => 0, 'enabled' => 0, 'available' => 0];
        }
        $query = SeoAiModel::query()->whereIn('api_connection_id', $connectionIds);
        $discovered = (clone $query)->count();
        $available = Schema::hasColumn('seo_ai_models', 'is_hidden')
            ? (clone $query)->where('is_hidden', true)->count()
            : 0;
        $enabledFamilies = 0;
        foreach ($this->familyInventory($userId, enabledOnly: true) as $row) {
            if (($row['visible'] ?? false) === true) {
                $enabledFamilies++;
            }
        }

        return [
            'discovered' => $discovered,
            'enabled' => $enabledFamilies,
            'available' => $available,
        ];
    }

    /**
     * @return array<int, array{discovered: int, enabled: int, available: int}>
     */
    public function connectionCounts(int $userId): array
    {
        $out = [];
        foreach ($this->aiConnections($userId) as $connection) {
            $id = (int) $connection->id;
            $base = SeoAiModel::query()->where('api_connection_id', $id);
            $discovered = (clone $base)->count();
            $available = Schema::hasColumn('seo_ai_models', 'is_hidden')
                ? (clone $base)->where('is_hidden', true)->count()
                : 0;
            $enabled = 0;
            foreach ($this->familyInventory($userId, enabledOnly: true, connectionId: $id) as $row) {
                if (($row['visible'] ?? false) === true) {
                    $enabled++;
                }
            }
            $out[$id] = [
                'discovered' => $discovered,
                'enabled' => $enabled,
                'available' => $available,
            ];
        }

        return $out;
    }

    /**
     * @param  array{search?: string, type?: string, status?: string, area?: string, provider?: string}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function availablePage(int $userId, ?int $connectionId, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $type = trim((string) ($filters['type'] ?? ''));
        $status = trim((string) ($filters['status'] ?? 'available'));
        $provider = trim((string) ($filters['provider'] ?? ''));
        $area = AiModelArea::tryFromMixed($filters['area'] ?? 'text');
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $rows = [];
        foreach ($this->familyInventory($userId, enabledOnly: false, connectionId: $connectionId) as $row) {
            $connection = $this->connectionById($userId, (int) ($row['connection_id'] ?? 0));
            $model = $this->firstModel($row);
            if ($connection === null || $model === null) {
                continue;
            }
            if ($provider !== '' && $provider !== 'all' && (string) $row['provider_key'] !== $provider) {
                continue;
            }
            $unknown = (bool) ($row['unknown'] ?? false);
            $inArea = $unknown
                ? $this->priorities->isExplicitlyAreaEnabled($model, $area)
                : $this->priorities->isAreaEnabled($model, $area, $connection);
            if ($status === 'available') {
                if ($inArea || $unknown || ! $this->rowSupportsArea($connection, $row, $area)) {
                    continue;
                }
            } elseif ($status === 'unknown') {
                if (! $unknown || $inArea) {
                    continue;
                }
                if (! $this->unknownRowMatchesArea($connection, $row, $area)) {
                    continue;
                }
            } elseif ($status === 'disabled') {
                if ((string) ($row['status'] ?? '') !== SeoAiModel::STATUS_INACTIVE) {
                    continue;
                }
            }
            if ($type !== '' && $type !== 'all' && (string) $row['type'] !== $type) {
                continue;
            }
            if ($search !== '') {
                $hay = mb_strtolower((string) $row['label'].' '.(string) $row['provider'].' '.(string) ($row['family_key'] ?? ''));
                foreach ($row['releases'] ?? [] as $release) {
                    if (is_array($release)) {
                        $hay .= ' '.mb_strtolower((string) ($release['raw'] ?? '').' '.(string) ($release['label'] ?? ''));
                    }
                }
                if (! str_contains($hay, $search)) {
                    continue;
                }
            }
            $row['source'] = (string) ($row['provider'] ?? '');
            if ($connection !== null) {
                $raw = (string) (($row['releases'][0]['raw'] ?? null) ?: ($row['family_key'] ?? ''));
                $presented = $this->executionLabels->present(
                    $connection,
                    $raw !== '' ? $raw : (string) $row['label'],
                    (string) $row['label'],
                    $userId,
                );
                $row['short_code'] = $presented['short_code'];
                $row['badge_variant'] = $presented['badge_variant'];
                $row['model_name'] = $presented['model_name'];
                $row['full_label'] = $presented['full_label'];
            }
            $rows[] = $row;
        }

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => $slice,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    public function displayLabel(string $providerKey, string $rawModelId, string $baseLabel): string
    {
        if (! ApiConnectionProviders::isAggregator($providerKey)) {
            return $baseLabel;
        }
        $slash = strpos($rawModelId, '/');
        if ($slash === false) {
            return $baseLabel;
        }
        $vendor = $this->vendorLabel(substr($rawModelId, 0, $slash));
        if ($vendor === '' || str_starts_with(mb_strtolower($baseLabel), mb_strtolower($vendor))) {
            return $baseLabel;
        }

        return $vendor.' · '.$baseLabel;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groups(int $userId, string $view = 'recommended'): array
    {
        $byProvider = [];
        foreach ($this->familyInventory($userId, enabledOnly: $view !== 'technical') as $row) {
            $key = (string) $row['provider_key'];
            $byProvider[$key] ??= [
                'provider' => $key,
                'label' => (string) $row['provider'],
                'items' => [],
            ];
            if ($view === 'recommended' && ($row['unknown'] ?? false) === true) {
                continue;
            }
            if ($view === 'custom' && ($row['unknown'] ?? false) !== true) {
                continue;
            }
            $byProvider[$key]['items'][] = $view === 'technical'
                ? $row
                : [
                    'family_key' => $row['family_key'],
                    'label' => $row['label'],
                    'hidden' => ! $row['visible'],
                    'ids' => $row['ids'],
                ];
        }

        return array_values($byProvider);
    }

    /**
     * @param  list<int>  $ids
     */
    public function setHidden(int $userId, array $ids, bool $hidden): void
    {
        if (! Schema::hasColumn('seo_ai_models', 'is_hidden') || $ids === []) {
            return;
        }
        SeoAiModel::query()
            ->whereIn('id', $ids)
            ->whereHas('apiConnection', function ($query) use ($userId): void {
                $query->where(function ($inner) use ($userId): void {
                    $inner->where('user_id', $userId)->orWhere('is_global', true);
                });
            })
            ->update(['is_hidden' => $hidden]);
        $this->forgetMemo();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function familyInventory(int $userId, bool $enabledOnly = false, ?int $connectionId = null): array
    {
        $memoKey = $userId.'|'.($enabledOnly ? '1' : '0').'|'.($connectionId ?? 'all');
        if (isset($this->inventoryMemo[$memoKey])) {
            return $this->inventoryMemo[$memoKey];
        }
        $families = [];
        $unknown = [];
        foreach ($this->aiConnections($userId) as $connection) {
            $this->connectionsById[(int) $connection->id] = $connection;
            if ($connectionId !== null && (int) $connection->id !== $connectionId) {
                continue;
            }
            $providerKey = (string) $connection->provider;
            $providerLabel = ApiConnectionProviders::label($providerKey);
            $modelsQuery = SeoAiModel::query()
                ->where('api_connection_id', $connection->id)
                ->orderBy('priority')
                ->orderBy('id');
            if ($enabledOnly && Schema::hasColumn('seo_ai_models', 'is_hidden')) {
                $modelsQuery->where('is_hidden', false);
            }
            foreach ($modelsQuery->get() as $model) {
                $this->modelsById[(int) $model->id] = $model;
                $raw = (string) $model->raw_model_name;
                $hidden = (bool) ($model->getAttribute('is_hidden') ?? false);
                $family = $this->families->familyForModelId($raw);
                $baseLabel = $family !== null
                    ? $family->displayName
                    : $this->labels->normal($raw, (string) $model->display_name);
                $label = $baseLabel;
                $release = [
                    'id' => (int) $model->id,
                    'raw' => $raw,
                    'label' => $this->labels->normal($raw, (string) $model->display_name),
                    'status' => (string) $model->status,
                    'hidden' => $hidden,
                    'updated_at' => optional($model->updated_at)?->toDateTimeString(),
                ];
                if ($family === null) {
                    $unknown[] = [
                        'identity' => $connection->id.'|unknown.'.$raw,
                        'family_key' => 'unknown.'.$raw,
                        'label' => $label,
                        'provider_key' => $providerKey,
                        'provider' => $providerLabel,
                        'connection_id' => (int) $connection->id,
                        'type' => $this->typeFromCategory((string) $model->category),
                        'status' => (string) $model->status,
                        'visible' => ! $hidden,
                        'updated_at' => $release['updated_at'],
                        'ids' => [(int) $model->id],
                        'release_count' => 1,
                        'releases' => [$release],
                        'unknown' => true,
                        'sort_key' => $this->priorities->sortKey($connection, $model),
                    ];

                    continue;
                }
                $key = $connection->id.'|'.$family->familyKey;
                if (! isset($families[$key])) {
                    $families[$key] = [
                        'identity' => $key,
                        'family_key' => $family->familyKey,
                        'label' => $label,
                        'provider_key' => $providerKey,
                        'provider' => $providerLabel,
                        'connection_id' => (int) $connection->id,
                        'type' => $this->normalizeType($family->modality),
                        'status' => SeoAiModel::STATUS_INACTIVE,
                        'visible' => false,
                        'updated_at' => $release['updated_at'],
                        'ids' => [],
                        'release_count' => 0,
                        'releases' => [],
                        'unknown' => false,
                        'sort_key' => $this->priorities->sortKey($connection, $model),
                    ];
                }
                $families[$key]['ids'][] = (int) $model->id;
                $families[$key]['releases'][] = $release;
                $families[$key]['release_count']++;
                if ((string) $model->status === SeoAiModel::STATUS_ACTIVE) {
                    $families[$key]['status'] = SeoAiModel::STATUS_ACTIVE;
                }
                if (! $hidden) {
                    $families[$key]['visible'] = true;
                }
                if (($release['updated_at'] ?? '') > (string) ($families[$key]['updated_at'] ?? '')) {
                    $families[$key]['updated_at'] = $release['updated_at'];
                }
            }
        }

        return $this->inventoryMemo[$memoKey] = array_values(array_merge(array_values($families), $unknown));
    }

    /**
     * @return list<int>
     */
    private function aiConnectionIds(int $userId): array
    {
        return array_map(
            static fn (ApiConnection $connection): int => (int) $connection->id,
            $this->aiConnections($userId),
        );
    }

    /**
     * @return list<ApiConnection>
     */
    private function aiConnections(int $userId): array
    {
        return $this->priorities->aiConnections($userId);
    }

    private function connectionById(int $userId, int $id): ?ApiConnection
    {
        if (isset($this->connectionsById[$id])) {
            return $this->connectionsById[$id];
        }
        foreach ($this->aiConnections($userId) as $connection) {
            $this->connectionsById[(int) $connection->id] = $connection;
            if ((int) $connection->id === $id) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function firstModel(array $row): ?SeoAiModel
    {
        $id = (int) (($row['ids'][0] ?? 0));
        if ($id <= 0) {
            return null;
        }
        if (isset($this->modelsById[$id])) {
            return $this->modelsById[$id];
        }
        $model = SeoAiModel::query()->find($id);
        if ($model instanceof SeoAiModel) {
            $this->modelsById[$id] = $model;
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowSupportsArea(ApiConnection $connection, array $row, AiModelArea $area): bool
    {
        foreach ($row['releases'] ?? [] as $release) {
            $raw = (string) ($release['raw'] ?? '');
            if ($raw === '') {
                continue;
            }
            foreach ($area->requiredCapabilityKeys() as $capability) {
                if ($this->capabilities->supports($connection, $raw, $capability)) {
                    return true;
                }
            }
        }
        $family = $this->families->find((string) ($row['family_key'] ?? ''));
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

    /**
     * Unknown inventory has no family; keep area-relevant rows via capabilities or name hints.
     *
     * @param  array<string, mixed>  $row
     */
    private function unknownRowMatchesArea(ApiConnection $connection, array $row, AiModelArea $area): bool
    {
        if ($this->rowSupportsArea($connection, $row, $area)) {
            return true;
        }

        $hay = mb_strtolower((string) ($row['label'] ?? '').' '.(string) ($row['family_key'] ?? ''));
        foreach ($row['releases'] ?? [] as $release) {
            if (is_array($release)) {
                $hay .= ' '.mb_strtolower((string) ($release['raw'] ?? '').' '.(string) ($release['label'] ?? ''));
            }
        }

        $hintsImage = str_contains($hay, 'image')
            || str_contains($hay, 'imagen')
            || str_contains($hay, 'flux')
            || str_contains($hay, 'dall-e')
            || str_contains($hay, 'stable-diffusion');
        $hintsVideo = str_contains($hay, 'video')
            || str_contains($hay, 'veo')
            || str_contains($hay, 'kling')
            || str_contains($hay, 'runway');

        return match ($area) {
            AiModelArea::Image => $hintsImage,
            AiModelArea::Video => $hintsVideo,
            AiModelArea::Text,
            AiModelArea::TextFast,
            AiModelArea::TextLongform,
            AiModelArea::TextReasoning => ! $hintsImage && ! $hintsVideo,
            default => ! $hintsImage && ! $hintsVideo,
        };
    }

    private function vendorLabel(string $vendor): string
    {
        return match (strtolower(trim($vendor))) {
            'google' => 'Google',
            'anthropic' => 'Anthropic',
            'deepseek' => 'DeepSeek',
            'qwen', 'alibaba' => 'Qwen',
            'meta-llama', 'meta' => 'Meta',
            'mistralai', 'mistral' => 'Mistral',
            'openai' => 'OpenAI',
            'x-ai' => 'xAI',
            default => ucfirst(str_replace(['-', '_'], ' ', trim($vendor))),
        };
    }

    private function normalizeType(string $modality): string
    {
        return match ($modality) {
            'image' => 'image',
            'video' => 'video',
            'multimodal' => 'multimodal',
            default => 'text',
        };
    }

    private function typeFromCategory(string $category): string
    {
        $lower = strtolower($category);
        if (str_contains($lower, 'image') || str_contains($lower, 'imagen')) {
            return 'image';
        }
        if (str_contains($lower, 'video') || str_contains($lower, 'veo')) {
            return 'video';
        }

        return 'text';
    }
}
