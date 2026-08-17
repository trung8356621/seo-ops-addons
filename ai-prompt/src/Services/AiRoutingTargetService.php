<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\AiPrompt\Models\AiRoutingProfile;
use Omnichannel\Addons\AiPrompt\Models\AiRoutingTarget;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelLabelPresenter;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use App\Models\ApiConnection;

final class AiRoutingTargetService
{
    public function __construct(
        private readonly ModelCapabilityRegistry $capabilities,
        private readonly AiModelFamilyCatalog $families = new AiModelFamilyCatalog(),
        private readonly AiModelLabelPresenter $labels = new AiModelLabelPresenter(),
        private readonly AiModelPriorityService $priorities = new AiModelPriorityService(),
    ) {}

    /** @var array<string, list<\Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate>> */
    private array $liveCandidatesMemo = [];

    public function forgetMemo(): void
    {
        $this->liveCandidatesMemo = [];
        $this->priorities->forgetMemo();
    }

    /**
     * @return list<AiRoutingTarget>
     */
    public function targetsFor(int $userId, string $profileKey): array
    {
        return AiRoutingTarget::query()
            ->with(['apiConnection', 'seoAiModel'])
            ->where('user_id', $userId)
            ->where('profile_key', $profileKey)
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  list<array{api_connection_id: int, model_key: string, enabled?: bool, options?: array<string, mixed>}>  $rows
     */
    public function replaceTargets(int $userId, string $profileKey, array $rows): void
    {
        $profile = AiExecutionProfile::tryFrom($profileKey);
        if ($profile === null) {
            throw new AiRoutingException('Unknown routing profile: '.$profileKey);
        }

        $this->ensureProfileRow($userId, $profile);

        $seen = [];
        $priority = 1;
        $keptIds = [];

        foreach ($rows as $row) {
            $connectionId = (int) ($row['api_connection_id'] ?? 0);
            $modelKey = trim((string) ($row['model_key'] ?? ''));
            if ($connectionId <= 0 || $modelKey === '') {
                continue;
            }

            $dedupe = $connectionId.'|'.$modelKey;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $connection = $this->scopedConnection($userId, $connectionId);
            $this->assertCompatible($profile, $connection, $modelKey);

            $seoModel = SeoAiModel::query()
                ->where('api_connection_id', $connectionId)
                ->where('raw_model_name', $modelKey)
                ->first();

            $target = AiRoutingTarget::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'profile_key' => $profileKey,
                    'api_connection_id' => $connectionId,
                    'model_key' => $modelKey,
                ],
                [
                    'profile_id' => AiRoutingProfile::query()
                        ->where('user_id', $userId)
                        ->where('key', $profileKey)
                        ->value('id'),
                    'seo_ai_model_id' => $seoModel?->id,
                    'priority' => $priority,
                    'enabled' => (bool) ($row['enabled'] ?? true),
                    'options' => is_array($row['options'] ?? null) ? $row['options'] : [],
                ],
            );
            $keptIds[] = (int) $target->id;
            $priority++;
        }

        AiRoutingTarget::query()
            ->where('user_id', $userId)
            ->where('profile_key', $profileKey)
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();
        $this->forgetMemo();
    }

    public function assertCompatible(AiExecutionProfile $profile, ApiConnection $connection, string $modelKey): void
    {
        foreach ($profile->requiredCapabilityKeys() as $capability) {
            if (! $this->capabilities->supports($connection, $modelKey, $capability)) {
                throw AiRoutingException::modelLacksCapability($modelKey, $capability);
            }
        }
    }

    public function scopedConnection(int $userId, int $connectionId): ApiConnection
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null) {
            throw new AiRoutingException('AI connection not found.');
        }

        $ownerId = $userId;
        $allowed = (int) $connection->user_id === $ownerId || (bool) $connection->is_global;
        if (! $allowed) {
            throw AiRoutingException::crossTenant();
        }

        return $connection;
    }

    /**
     * @return list<RoutedAiCandidate>
     */
    public function eligibleCandidates(int $userId, AiExecutionProfile $profile, AiRoutingContext $context): array
    {
        $settings = $this->profileSettings($userId, $profile);
        $execAllowed = is_array($settings['allowed_execution_keys'] ?? null)
            ? array_values(array_filter(
                array_map(static fn (mixed $key): string => (string) $key, $settings['allowed_execution_keys']),
                static fn (string $key): bool => $key !== '',
            ))
            : [];
        $allowed = $context->allowedFamilyKeys ?? $this->normalizedAllowedFamilies($settings);
        $out = [];
        foreach ($this->liveCompatibleCandidates($userId, $profile) as $candidate) {
            $family = $this->families->familyForModelId($candidate->model);
            if ($family === null) {
                continue;
            }
            if ($execAllowed !== []
                && ! in_array(((int) $candidate->connection->id).'|'.$family->familyKey, $execAllowed, true)
                && ! in_array($family->familyKey, $execAllowed, true)) {
                continue;
            }
            if ($allowed !== [] && ! in_array($family->familyKey, $allowed, true)) {
                continue;
            }
            $out[] = $candidate;
        }

        return array_values($out);
    }

    /**
     * @return list<RoutedAiCandidate>
     */
    public function liveCompatibleCandidates(int $userId, AiExecutionProfile $profile): array
    {
        $memoKey = $userId.'|'.$profile->value;
        if (isset($this->liveCandidatesMemo[$memoKey])) {
            return $this->liveCandidatesMemo[$memoKey];
        }
        $area = \Omnichannel\Addons\AiPrompt\Support\AiModelArea::fromProfile($profile);
        $out = [];
        foreach ($this->priorities->areaEnabledModels($userId, $area) as $model) {
            $connection = $model->apiConnection;
            if (! $connection instanceof ApiConnection
                || (string) $connection->status !== 'active'
                || blank($connection->api_key)) {
                continue;
            }
            $modelKey = (string) $model->raw_model_name;
            if (! $this->capabilities->satisfiesAll($connection, $modelKey, $profile->requiredCapabilityKeys())) {
                continue;
            }
            if (! GeminiModelVersionPolicy::isEligibleForAutoRouting($modelKey)) {
                continue;
            }
            if ($this->families->familyForModelId($modelKey) === null) {
                continue;
            }
            $out[] = new RoutedAiCandidate(
                profile: $profile->value,
                connection: $connection,
                provider: (string) $connection->provider,
                model: $modelKey,
                capabilities: $this->capabilities->capabilitiesFor($connection, $modelKey),
                priority: $this->priorities->areaPriority($model, $area, $connection),
                options: [],
                seoAiModelId: (int) $model->id,
            );
        }
        usort($out, static function (RoutedAiCandidate $a, RoutedAiCandidate $b): int {
            $cmp = $a->priority <=> $b->priority;
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($a->seoAiModelId ?? 0) <=> ($b->seoAiModelId ?? 0);
        });

        return $this->liveCandidatesMemo[$memoKey] = array_values($out);
    }

    /**
     * @param  list<string>  $selectionKeys
     */
    private function selectionIncludes(array $selectionKeys, int $connectionId, string $familyKey): bool
    {
        if ($selectionKeys === []) {
            return true;
        }
        $execKey = $connectionId.'|'.$familyKey;
        if (in_array($execKey, $selectionKeys, true)) {
            return true;
        }

        return in_array($familyKey, $selectionKeys, true);
    }

    /**
     * @param  list<string>  $selectionKeys
     * @return list<string>
     */
    private function familyKeysFromSelection(array $selectionKeys): array
    {
        $out = [];
        foreach ($selectionKeys as $key) {
            if (preg_match('/^\d+\|(.+)$/', $key, $matches) === 1) {
                $out[] = $matches[1];
                continue;
            }
            if ($key !== '' && $key !== AiModelFamilyCatalog::AUTOMATIC) {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $selectionKeys
     * @return list<string>
     */
    private function executionKeysFromSelection(array $selectionKeys): array
    {
        $out = [];
        foreach ($selectionKeys as $key) {
            if (preg_match('/^\d+\|.+$/', $key) === 1) {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, string>  "connectionId|modelKey" => label
     */
    public function eligibleOptionMap(int $userId, AiExecutionProfile $profile): array
    {
        $connections = ApiConnection::query()
            ->where('status', 'active')
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('is_global', true);
            })
            ->orderBy('name')
            ->get();

        $options = [];
        foreach ($connections as $connection) {
            $models = SeoAiModel::query()
                ->where('api_connection_id', $connection->id)
                ->where('status', SeoAiModel::STATUS_ACTIVE)
                ->when(
                    \Illuminate\Support\Facades\Schema::hasColumn('seo_ai_models', 'is_hidden'),
                    static fn ($query) => $query->where('is_hidden', false),
                )
                ->orderByDesc('priority')
                ->get(['id', 'raw_model_name', 'display_name', 'api_connection_id']);

            foreach ($models as $model) {
                $key = (string) $model->raw_model_name;
                if (! $this->capabilities->satisfiesAll($connection, $key, $profile->requiredCapabilityKeys())) {
                    continue;
                }
                if (! GeminiModelVersionPolicy::isEligibleForAutoRouting($key)) {
                    continue;
                }
                if ($this->families->familyForModelId($key) === null) {
                    continue;
                }
                $options[(int) $connection->id.'|'.$key] = $this->labels->normal(
                    $key,
                    (string) ($model->display_name ?: $key),
                );
            }
        }

        return $options;
    }

    /**
     * @return array<string, array{short_code: string, badge_variant: string, model_name: string, full_label: string}>
     */
    public function eligibleExecutionOptionMap(int $userId, AiExecutionProfile $profile): array
    {
        return app(AiModelInventory::class)->executionOptions($userId, $profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function profileSettings(int $userId, AiExecutionProfile $profile): array
    {
        $this->ensureProfileRow($userId, $profile);
        $row = AiRoutingProfile::query()
            ->where('user_id', $userId)
            ->where('key', $profile->value)
            ->first();

        return is_array($row?->settings) ? $row->settings : [];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function writeProfileSettings(int $userId, AiExecutionProfile $profile, array $settings): void
    {
        $this->ensureProfileRow($userId, $profile);
        $current = $this->profileSettings($userId, $profile);
        AiRoutingProfile::query()
            ->where('user_id', $userId)
            ->where('key', $profile->value)
            ->update(['settings' => array_merge($current, $settings)]);
    }

    /**
     * @param  list<string>  $familyKeys
     */
    public function saveSimplifiedSelection(
        int $userId,
        AiExecutionProfile $profile,
        array $familyKeys,
        AiUsageMode $mode,
        bool $enabled = true,
        bool $reorder = false,
    ): void {
        $automatic = $familyKeys === [] || in_array(AiModelFamilyCatalog::AUTOMATIC, $familyKeys, true);
        $normalized = $automatic ? [] : array_values(array_filter(
            $familyKeys,
            static fn (string $key): bool => $key !== AiModelFamilyCatalog::AUTOMATIC,
        ));

        $existing = $this->targetsFor($userId, $profile->value);
        $keep = [];
        foreach ($existing as $target) {
            $family = $this->families->familyForModelId((string) $target->model_key);
            if ($family === null) {
                continue;
            }
            if ($normalized !== [] && ! $this->selectionIncludes($normalized, (int) $target->api_connection_id, $family->familyKey)) {
                continue;
            }
            $keep[] = [
                'api_connection_id' => (int) $target->api_connection_id,
                'model_key' => (string) $target->model_key,
                'enabled' => $enabled ? (bool) $target->enabled : false,
                'options' => is_array($target->options) ? $target->options : [],
            ];
        }

        if ($normalized !== []) {
            $keep = $this->expandMissingFamilyTargets($userId, $profile, $normalized, $keep);
        }

        $this->replaceTargets($userId, $profile->value, $keep);
        $this->writeProfileSettings($userId, $profile, [
            'usage_mode' => $mode->value,
            'allowed_family_keys' => $this->familyKeysFromSelection($normalized),
            'allowed_execution_keys' => $this->executionKeysFromSelection($normalized),
            'preserve_explicit_order' => true,
            'simplified' => true,
            'enabled' => $enabled,
        ]);

        AiRoutingProfile::query()
            ->where('user_id', $userId)
            ->where('key', $profile->value)
            ->update(['enabled' => $enabled]);
    }

    /**
     * @param  list<array{api_connection_id: int, model_key: string, enabled?: bool, options?: array<string, mixed>}>  $keep
     * @param  list<string>  $familyKeys
     * @return list<array{api_connection_id: int, model_key: string, enabled?: bool, options?: array<string, mixed>}>
     */
    private function expandMissingFamilyTargets(int $userId, AiExecutionProfile $profile, array $familyKeys, array $keep): array
    {
        $seen = [];
        foreach ($keep as $row) {
            $seen[(int) $row['api_connection_id'].'|'.$row['model_key']] = true;
        }

        foreach ($this->eligibleOptionMap($userId, $profile) as $targetKey => $label) {
            unset($label);
            $parts = explode('|', (string) $targetKey, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $family = $this->families->familyForModelId($parts[1]);
            if ($family === null) {
                continue;
            }
            if (! $this->selectionIncludes($familyKeys, (int) $parts[0], $family->familyKey)) {
                continue;
            }
            if (isset($seen[$targetKey])) {
                continue;
            }
            $keep[] = [
                'api_connection_id' => (int) $parts[0],
                'model_key' => $parts[1],
                'enabled' => true,
                'options' => [],
            ];
            $seen[$targetKey] = true;
        }

        return $keep;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function normalizedAllowedFamilies(array $settings): array
    {
        $raw = $settings['allowed_family_keys'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => (string) $key, $raw),
            static fn (string $key): bool => $key !== '' && $key !== AiModelFamilyCatalog::AUTOMATIC,
        ));
    }

    private function ensureProfileRow(int $userId, AiExecutionProfile $profile): void
    {
        AiRoutingProfile::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'key' => $profile->value,
            ],
            [
                'name' => $profile->displayName(),
                'description' => $profile->description(),
                'required_capabilities' => $profile->requiredCapabilityKeys(),
                'enabled' => true,
            ],
        );
    }

    private function globalUsageMode(): ?AiUsageMode
    {
        try {
            if (! function_exists('app')) {
                return null;
            }
            $raw = app(\Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService::class)
                ->getDefaultAiUsageModeOrNull();

            return AiUsageMode::tryFromMixed($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
