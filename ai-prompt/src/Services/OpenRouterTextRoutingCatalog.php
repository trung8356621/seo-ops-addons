<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Support\AiModelCategory;

/**
 * Idempotent OpenRouter Text catalog for AI Center Models + Routing Custom pools.
 */
final class OpenRouterTextRoutingCatalog
{
    /** @var array<string, string> raw_model_name => display_name */
    public const MODELS = [
        'openai/gpt-5.4' => 'GPT-5.4',
        'openai/gpt-5.4-mini' => 'GPT-5.4 Mini',
        'openai/gpt-5.4-nano' => 'GPT-5.4 Nano',
        'anthropic/claude-sonnet-4.6' => 'Claude Sonnet 4.6',
        'anthropic/claude-haiku-4.5' => 'Claude Haiku 4.5',
        'google/gemini-3.5-flash' => 'Gemini 3.5 Flash',
        'google/gemini-3.5-flash-lite' => 'Gemini 3.5 Flash Lite',
        'google/gemini-3.1-pro-preview' => 'Gemini 3.1 Pro',
        'deepseek/deepseek-v3.2' => 'DeepSeek V3.2',
        'qwen/qwen3.6-flash' => 'Qwen 3.6 Flash',
    ];

    /** @var array<string, list<string>> profile value => raw OpenRouter model ids */
    public const PROFILE_MODELS = [
        AiExecutionProfile::TextFast->value => [
            'openai/gpt-5.4-nano',
            'openai/gpt-5.4-mini',
            'anthropic/claude-haiku-4.5',
            'google/gemini-3.5-flash-lite',
            'google/gemini-3.5-flash',
            'deepseek/deepseek-v3.2',
            'qwen/qwen3.6-flash',
        ],
        AiExecutionProfile::TextLongform->value => [
            'anthropic/claude-sonnet-4.6',
            'google/gemini-3.5-flash',
            'openai/gpt-5.4-mini',
            'openai/gpt-5.4',
            'deepseek/deepseek-v3.2',
            'qwen/qwen3.6-flash',
        ],
        AiExecutionProfile::TextReasoning->value => [
            'openai/gpt-5.4',
            'anthropic/claude-sonnet-4.6',
            'google/gemini-3.1-pro-preview',
            'google/gemini-3.5-flash',
            'openai/gpt-5.4-mini',
            'deepseek/deepseek-v3.2',
        ],
    ];

    public function __construct(
        private readonly AiModelFamilyCatalog $families = new AiModelFamilyCatalog(),
        private readonly ?AiModelPriorityService $priorities = null,
        private readonly ?AiRoutingTargetService $targets = null,
        private readonly ?AiModelInventory $inventory = null,
    ) {}

    private function priorities(): AiModelPriorityService
    {
        return $this->priorities ?? app(AiModelPriorityService::class);
    }

    private function targets(): AiRoutingTargetService
    {
        return $this->targets ?? app(AiRoutingTargetService::class);
    }

    private function inventory(): AiModelInventory
    {
        return $this->inventory ?? app(AiModelInventory::class);
    }

    /**
     * @return array{
     *   connections: int,
     *   models_upserted: int,
     *   models_enabled: int,
     *   profiles_updated: int,
     *   execution_keys: array<string, list<string>>
     * }
     */
    public function apply(?int $userId = null): array
    {
        $priorities = $this->priorities();
        $targets = $this->targets();
        $inventory = $this->inventory();

        $query = ApiConnection::query()
            ->where('provider', ApiConnectionProviders::OPENROUTER)
            ->where('status', 'active')
            ->where(function ($inner): void {
                $inner->whereNotNull('api_key')->where('api_key', '!=', '');
            });
        if ($userId !== null) {
            $query->where(function ($inner) use ($userId): void {
                $inner->where('user_id', $userId)->orWhere('is_global', true);
            });
        }

        $connections = $query->orderBy('id')->get();
        $modelsUpserted = 0;
        $modelsEnabled = 0;
        $profilesUpdated = 0;
        $executionKeys = [];

        $ownerIds = [];
        foreach ($connections as $connection) {
            $result = $this->ensureModelsForConnection($connection, $priorities);
            $modelsUpserted += $result['upserted'];
            $modelsEnabled += $result['enabled'];
            $ownerIds[(int) $connection->user_id] = true;
            if ((bool) $connection->is_global) {
                foreach ($this->userIdsWithAiRouting() as $uid) {
                    $ownerIds[$uid] = true;
                }
            }
        }

        $inventory->forget();
        $priorities->forgetMemo();
        $targets->forgetMemo();

        foreach (array_keys($ownerIds) as $ownerId) {
            $applied = $this->applyTextRoutingForUser((int) $ownerId, $targets, $inventory);
            $profilesUpdated += $applied['profiles'];
            $executionKeys[(string) $ownerId] = $applied['keys'];
        }

        $inventory->forget();
        $priorities->forgetMemo();
        $targets->forgetMemo();

        return [
            'connections' => $connections->count(),
            'models_upserted' => $modelsUpserted,
            'models_enabled' => $modelsEnabled,
            'profiles_updated' => $profilesUpdated,
            'execution_keys' => $executionKeys,
        ];
    }

    /**
     * @return array{upserted: int, enabled: int}
     */
    private function ensureModelsForConnection(ApiConnection $connection, AiModelPriorityService $priorities): array
    {
        $upserted = 0;
        $enabled = 0;
        $ids = [];
        foreach (self::MODELS as $raw => $displayName) {
            $model = SeoAiModel::query()->firstOrNew([
                'api_connection_id' => (int) $connection->id,
                'raw_model_name' => $raw,
            ]);
            $dirty = ! $model->exists
                || (string) $model->display_name !== $displayName
                || (string) $model->status !== SeoAiModel::STATUS_ACTIVE
                || (bool) ($model->getAttribute('is_hidden') ?? false) === true;
            $model->display_name = $displayName;
            $model->status = SeoAiModel::STATUS_ACTIVE;
            $model->is_hidden = false;
            if ($model->category === null || $model->category === '' || $model->category === 'unknown') {
                $model->category = $this->categoryForRaw($raw);
            }
            if ($model->priority === null || (int) $model->priority <= 0) {
                $model->priority = 100;
            }
            if ($dirty || $model->isDirty()) {
                $model->save();
                $upserted++;
            }
            $ids[] = (int) $model->id;
        }

        $userId = (int) $connection->user_id;
        // Enable each curated model in EVERY text area listed in PROFILE_MODELS.
        // Skip areas already explicitly enabled so manual order is preserved.
        $areaProfiles = [
            [AiModelArea::TextFast, AiExecutionProfile::TextFast],
            [AiModelArea::TextLongform, AiExecutionProfile::TextLongform],
            [AiModelArea::TextReasoning, AiExecutionProfile::TextReasoning],
        ];
        foreach ($areaProfiles as [$area, $profile]) {
            foreach (self::PROFILE_MODELS[$profile->value] ?? [] as $raw) {
                $model = SeoAiModel::query()
                    ->where('api_connection_id', (int) $connection->id)
                    ->where('raw_model_name', $raw)
                    ->first();
                if (! $model instanceof SeoAiModel) {
                    continue;
                }
                if ($priorities->isExplicitlyAreaEnabled($model, $area)) {
                    continue;
                }
                $priorities->appendToArea($userId, $area, [(int) $model->id]);
            }
        }
        $enabled = count($ids);

        return ['upserted' => $upserted, 'enabled' => $enabled];
    }

    /**
     * @return array{profiles: int, keys: array<string, list<string>>}
     */
    private function applyTextRoutingForUser(
        int $userId,
        AiRoutingTargetService $targets,
        AiModelInventory $inventory,
    ): array {
        $mode = AiUsageMode::tryFromMixed(
            app(SeoCreateArticleSettingsService::class)->getDefaultAiUsageMode(),
        ) ?? AiUsageMode::Economy;

        $updated = 0;
        $keysByProfile = [];
        foreach ([
            AiExecutionProfile::TextFast,
            AiExecutionProfile::TextLongform,
            AiExecutionProfile::TextReasoning,
        ] as $profile) {
            $wantedRaws = self::PROFILE_MODELS[$profile->value] ?? [];
            $merged = $this->mergeExecutionKeys($userId, $profile, $wantedRaws, $targets, $inventory);
            $keysByProfile[$profile->value] = $merged;
            $targets->saveSimplifiedSelection(
                $userId,
                $profile,
                $merged,
                $mode,
                true,
                false,
            );
            $updated++;
        }

        return ['profiles' => $updated, 'keys' => $keysByProfile];
    }

    /**
     * Keep existing (or Automatic-eligible) targets that are not curated OpenRouter catalog
     * rows, then union the profile-specific OpenRouter pool. Prevents Automatic→Custom from
     * dumping every newly enabled OR model into every Text profile.
     *
     * @param  list<string>  $wantedRaws
     * @return list<string> connectionId|familyKey
     */
    private function mergeExecutionKeys(
        int $userId,
        AiExecutionProfile $profile,
        array $wantedRaws,
        AiRoutingTargetService $targets,
        AiModelInventory $inventory,
    ): array {
        $settings = $targets->profileSettings($userId, $profile);
        $families = (array) ($settings['allowed_family_keys'] ?? []);
        $execution = (array) ($settings['allowed_execution_keys'] ?? []);
        $wasAutomatic = $execution === [] && ($families === [] || in_array(AiModelFamilyCatalog::AUTOMATIC, $families, true));

        $base = [];
        if ($wasAutomatic) {
            foreach (array_keys($inventory->executionOptions($userId, $profile)) as $execKey) {
                $base[(string) $execKey] = true;
            }
        } else {
            foreach ($execution as $key) {
                $key = (string) $key;
                if ($key !== '') {
                    $base[$key] = true;
                }
            }
            foreach ($families as $key) {
                $key = (string) $key;
                if ($key === '' || $key === AiModelFamilyCatalog::AUTOMATIC) {
                    continue;
                }
                if (preg_match('/^\d+\|.+$/', $key) === 1) {
                    $base[$key] = true;
                }
            }
        }

        $orConnectionIds = $this->openRouterConnectionIdsForUser($userId);
        $curatedFamilies = $this->curatedOpenRouterFamilyKeys();
        $kept = [];
        foreach (array_keys($base) as $execKey) {
            if ($this->isCuratedOpenRouterExecutionKey((string) $execKey, $orConnectionIds, $curatedFamilies)) {
                continue;
            }
            $kept[(string) $execKey] = true;
        }

        foreach ($this->openRouterExecutionKeysForRaws($userId, $wantedRaws) as $execKey) {
            $kept[$execKey] = true;
        }

        return array_values(array_keys($kept));
    }

    /**
     * @return list<int>
     */
    private function openRouterConnectionIdsForUser(int $userId): array
    {
        return ApiConnection::query()
            ->where('provider', ApiConnectionProviders::OPENROUTER)
            ->where('status', 'active')
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('is_global', true);
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<string, true>
     */
    private function curatedOpenRouterFamilyKeys(): array
    {
        $out = [];
        foreach (array_keys(self::MODELS) as $raw) {
            $family = $this->families->familyForModelId($raw);
            if ($family !== null) {
                $out[$family->familyKey] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $orConnectionIds
     * @param  array<string, true>  $curatedFamilies
     */
    private function isCuratedOpenRouterExecutionKey(
        string $execKey,
        array $orConnectionIds,
        array $curatedFamilies,
    ): bool {
        if (preg_match('/^(\d+)\|(.+)$/', $execKey, $matches) !== 1) {
            return false;
        }
        $connectionId = (int) $matches[1];
        $familyKey = (string) $matches[2];

        return in_array($connectionId, $orConnectionIds, true) && isset($curatedFamilies[$familyKey]);
    }

    /**
     * @param  list<string>  $raws
     * @return list<string>
     */
    private function openRouterExecutionKeysForRaws(int $userId, array $raws): array
    {
        $out = [];
        $connections = ApiConnection::query()
            ->where('provider', ApiConnectionProviders::OPENROUTER)
            ->where('status', 'active')
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('is_global', true);
            })
            ->get();

        foreach ($connections as $connection) {
            foreach ($raws as $raw) {
                $family = $this->families->familyForModelId($raw);
                if ($family === null) {
                    continue;
                }
                $exists = SeoAiModel::query()
                    ->where('api_connection_id', $connection->id)
                    ->where('raw_model_name', $raw)
                    ->where('status', SeoAiModel::STATUS_ACTIVE)
                    ->exists();
                if (! $exists) {
                    continue;
                }
                $out[] = ((int) $connection->id).'|'.$family->familyKey;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<int>
     */
    private function userIdsWithAiRouting(): array
    {
        return ApiConnection::query()
            ->whereIn('provider', [
                ApiConnectionProviders::OPENROUTER,
                ApiConnectionProviders::GEMINI,
                ApiConnectionProviders::DEEPSEEK,
                ApiConnectionProviders::CLAUDE,
            ])
            ->where('status', 'active')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Text capability areas where a curated OpenRouter raw id is a PROFILE_MODELS member.
     *
     * @return list<AiModelArea>
     */
    public static function membershipAreasForRaw(string $raw): array
    {
        $out = [];
        if (in_array($raw, self::PROFILE_MODELS[AiExecutionProfile::TextFast->value] ?? [], true)) {
            $out[] = AiModelArea::TextFast;
        }
        if (in_array($raw, self::PROFILE_MODELS[AiExecutionProfile::TextLongform->value] ?? [], true)) {
            $out[] = AiModelArea::TextLongform;
        }
        if (in_array($raw, self::PROFILE_MODELS[AiExecutionProfile::TextReasoning->value] ?? [], true)) {
            $out[] = AiModelArea::TextReasoning;
        }

        return $out !== [] ? $out : [AiModelArea::TextFast];
    }

    /**
     * Preferred primary-type metadata only (display / classifier). Runtime membership
     * uses all PROFILE_MODELS areas — do not treat this as exclusive route membership.
     */
    public static function primaryAreaForRaw(string $raw): AiModelArea
    {
        $areas = self::membershipAreasForRaw($raw);
        if (in_array(AiModelArea::TextReasoning, $areas, true)) {
            return AiModelArea::TextReasoning;
        }
        if (in_array(AiModelArea::TextLongform, $areas, true)) {
            return AiModelArea::TextLongform;
        }

        return AiModelArea::TextFast;
    }

    private function categoryForRaw(string $raw): string
    {
        $family = $this->families->familyForModelId($raw);

        return match ($family?->familyKey) {
            'gemini.pro' => AiModelCategory::GEMINI_PRO,
            'gemini.flash', 'gemini.flash_lite' => AiModelCategory::GEMINI_FLASH,
            'claude.sonnet' => AiModelCategory::CLAUDE_SONNET,
            'claude.haiku' => AiModelCategory::CLAUDE_HAIKU,
            'claude.opus' => AiModelCategory::CLAUDE_OPUS,
            'deepseek.chat', 'deepseek.v32' => AiModelCategory::DEEPSEEK_CHAT,
            'deepseek.reasoner' => AiModelCategory::DEEPSEEK_REASONER,
            default => 'unknown',
        };
    }
}
