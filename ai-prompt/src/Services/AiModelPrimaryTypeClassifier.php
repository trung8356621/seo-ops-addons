<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\AiRoutingProfile;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Assigns Models-tab primary type. Manual override in omi_areas / omi_primary_type_source wins over sync.
 */
final class AiModelPrimaryTypeClassifier
{
    public function __construct(
        private readonly AiModelPriorityService $priorities = new AiModelPriorityService(),
        private readonly ModelCapabilityRegistry $capabilities = new ModelCapabilityRegistry(),
        private readonly AiModelFamilyCatalog $families = new AiModelFamilyCatalog(),
    ) {}

    /**
     * @return array{classified: int, free_enabled: int, skipped_manual: int, excluded: int}
     */
    public function classifyConnection(ApiConnection $connection): array
    {
        $classified = 0;
        $freeEnabled = 0;
        $skippedManual = 0;
        $excluded = 0;
        $userId = (int) $connection->user_id;
        $routingHints = $this->routingHintsForUser($userId, (int) $connection->id);

        $models = SeoAiModel::query()
            ->where('api_connection_id', $connection->id)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->get();

        foreach ($models as $model) {
            $caps = is_array($model->capabilities) ? $model->capabilities : [];
            $raw = (string) $model->raw_model_name;
            $isOpenRouter = OpenRouterModelEconomics::isOpenRouterProvider((string) $connection->provider);

            if ($this->isImageOrVideo($connection, $raw, $caps)) {
                continue;
            }

            if ($isOpenRouter && ! OpenRouterModelEconomics::isChatTextModel($caps, $raw)) {
                $excluded++;
                continue;
            }

            if ($this->isManual($caps)) {
                $this->ensureManualAreaEnabled($model);
                $skippedManual++;
                if ($isOpenRouter && OpenRouterModelEconomics::isFree($caps, $raw)) {
                    $this->unhideIfFreeChat($model, $caps, $raw, $isOpenRouter);
                    $freeEnabled++;
                }
                continue;
            }

            $area = $this->classify($connection, $model, $caps, $raw, $routingHints);
            $this->writeAutoPrimary($model, $area);
            $classified++;

            if ($isOpenRouter && OpenRouterModelEconomics::isFree($caps, $raw)) {
                $this->unhideIfFreeChat($model, $caps, $raw, $isOpenRouter);
                $this->restoreAutoSource($model, $area);
                $freeEnabled++;
            } else {
                $this->migrateLegacyTextIfEnabled($model, $area);
            }
        }

        $this->priorities->forgetMemo();

        return [
            'classified' => $classified,
            'free_enabled' => $freeEnabled,
            'skipped_manual' => $skippedManual,
            'excluded' => $excluded,
        ];
    }

    /**
     * @return array{classified: int, free_enabled: int, skipped_manual: int, excluded: int}
     */
    public function classifyForUser(int $userId): array
    {
        $totals = ['classified' => 0, 'free_enabled' => 0, 'skipped_manual' => 0, 'excluded' => 0];
        $connections = ApiConnection::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('is_global', true);
            })
            ->where('status', 'active')
            ->get();
        foreach ($connections as $connection) {
            $row = $this->classifyConnection($connection);
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $row[$key];
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $caps
     */
    public function classify(
        ApiConnection $connection,
        SeoAiModel $model,
        array $caps,
        string $raw,
        array $routingHints = [],
    ): AiModelArea {
        $rawLower = strtolower($raw);
            $hint = $routingHints[(int) $model->id] ?? $routingHints[$rawLower] ?? null;
        if ($hint instanceof AiModelArea && $hint->isTextPrimary()
            && ! OpenRouterModelEconomics::isFree($caps, $raw)) {
            return $hint;
        }

        if (OpenRouterModelEconomics::isOpenRouterFreeRouter($raw)) {
            return AiModelArea::TextLongform;
        }

        $modality = OpenRouterModelEconomics::architectureModality($caps);
        $meta = is_array($caps['provider_metadata'] ?? null) ? $caps['provider_metadata'] : [];
        $contextLength = (int) ($meta['context_length'] ?? 0);
        $hasReasoningCap = $this->capabilities->supports($connection, $raw, AiModelCapability::TextReasoning->value);

        $family = $this->families->familyForModelId($raw);
        $speedTier = $family?->speedTier ?? 0;
        $qualityTier = $family?->qualityTier ?? 0;

        $nameHay = $rawLower.' '.strtolower((string) $model->display_name);
        $idHay = $nameHay.' '.$modality;

        if ($this->looksReasoning($nameHay)) {
            return AiModelArea::TextReasoning;
        }

        if ($speedTier >= 3 || $this->looksFast($nameHay)) {
            return AiModelArea::TextFast;
        }

        if ($contextLength >= 100_000 || $this->looksLongform($idHay, $qualityTier, $contextLength)) {
            return AiModelArea::TextLongform;
        }

        if ($hasReasoningCap && $qualityTier >= 3) {
            return AiModelArea::TextReasoning;
        }

        return $contextLength >= 32_000 ? AiModelArea::TextLongform : AiModelArea::TextFast;
    }

    /**
     * @param  array<string, mixed>  $caps
     */
    private function isManual(array $caps): bool
    {
        if (($caps[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] ?? '') === AiModelArea::SOURCE_MANUAL) {
            return true;
        }
        $areas = is_array($caps[AiModelPriorityService::AREAS_KEY] ?? null)
            ? $caps[AiModelPriorityService::AREAS_KEY]
            : [];
        foreach (AiModelArea::textPrimaryCases() as $area) {
            $bag = $areas[$area->value] ?? null;
            if (is_array($bag) && ($bag['source'] ?? '') === AiModelArea::SOURCE_MANUAL) {
                return true;
            }
        }

        return false;
    }

    private function writeAutoPrimary(SeoAiModel $model, AiModelArea $area): void
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        if (($caps[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] ?? '') === AiModelArea::SOURCE_MANUAL) {
            return;
        }
        $caps[AiModelArea::PRIMARY_TYPE_KEY] = $area->value;
        $caps[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] = AiModelArea::SOURCE_AUTO;
        $areas = is_array($caps[AiModelPriorityService::AREAS_KEY] ?? null)
            ? $caps[AiModelPriorityService::AREAS_KEY]
            : [];
        $legacy = is_array($areas[AiModelArea::Text->value] ?? null) ? $areas[AiModelArea::Text->value] : null;
        $legacyEnabled = is_array($legacy) && ! empty($legacy['enabled']);
        $legacyPriority = is_array($legacy) && isset($legacy['priority']) ? (int) $legacy['priority'] : null;

        $anyNew = false;
        foreach (AiModelArea::textPrimaryCases() as $textArea) {
            $bag = $areas[$textArea->value] ?? null;
            if (is_array($bag) && array_key_exists('enabled', $bag)) {
                $anyNew = true;
                break;
            }
        }

        if (! $anyNew && $legacyEnabled) {
            foreach (AiModelArea::textPrimaryCases() as $textArea) {
                $enabled = $textArea === $area;
                $areas[$textArea->value] = [
                    'enabled' => $enabled,
                    'priority' => $legacyPriority ?? (int) ($model->priority ?: 100),
                    'source' => AiModelArea::SOURCE_AUTO,
                ];
            }
        } elseif (! $anyNew) {
            $areas[$area->value] = [
                'enabled' => ! empty($areas[$area->value]['enabled']),
                'priority' => (int) (($areas[$area->value]['priority'] ?? null) ?: ($model->priority ?: 100)),
                'source' => AiModelArea::SOURCE_AUTO,
            ];
        }

        $caps[AiModelPriorityService::AREAS_KEY] = $areas;
        $model->capabilities = $caps;
        $model->save();
    }

    private function migrateLegacyTextIfEnabled(SeoAiModel $model, AiModelArea $area): void
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $areas = is_array($caps[AiModelPriorityService::AREAS_KEY] ?? null)
            ? $caps[AiModelPriorityService::AREAS_KEY]
            : [];
        $legacy = $areas[AiModelArea::Text->value] ?? null;
        if (! is_array($legacy) || empty($legacy['enabled'])) {
            return;
        }
        foreach (AiModelArea::textPrimaryCases() as $textArea) {
            $bag = $areas[$textArea->value] ?? null;
            if (is_array($bag) && array_key_exists('enabled', $bag)) {
                return;
            }
        }
        $this->writeAutoPrimary($model, $area);
    }

    private function ensureManualAreaEnabled(SeoAiModel $model): void
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $primary = AiModelArea::tryFromMixed($caps[AiModelArea::PRIMARY_TYPE_KEY] ?? '');
        if (! $primary->isTextPrimary()) {
            return;
        }
        $areas = is_array($caps[AiModelPriorityService::AREAS_KEY] ?? null)
            ? $caps[AiModelPriorityService::AREAS_KEY]
            : [];
        $bag = $areas[$primary->value] ?? null;
        if (is_array($bag) && ! empty($bag['enabled'])) {
            return;
        }
        $areas[$primary->value] = [
            'enabled' => true,
            'priority' => (int) ($bag['priority'] ?? $model->priority ?: 100),
            'source' => AiModelArea::SOURCE_MANUAL,
        ];
        $caps[AiModelPriorityService::AREAS_KEY] = $areas;
        $model->capabilities = $caps;
        $model->save();
    }

    /**
     * Classify primary type for free inventory only. Do NOT auto-enable into a
     * capability route — free is metadata; user must Add / drag into Models.
     */
    private function restoreAutoSource(SeoAiModel $model, AiModelArea $area): void
    {
        $model->refresh();
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        if (($caps[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] ?? '') === AiModelArea::SOURCE_MANUAL) {
            return;
        }
        $caps[AiModelArea::PRIMARY_TYPE_KEY] = $area->value;
        $caps[AiModelArea::PRIMARY_TYPE_SOURCE_KEY] = AiModelArea::SOURCE_AUTO;
        $areas = is_array($caps[AiModelPriorityService::AREAS_KEY] ?? null)
            ? $caps[AiModelPriorityService::AREAS_KEY]
            : [];
        foreach (AiModelArea::textPrimaryCases() as $textArea) {
            $bag = is_array($areas[$textArea->value] ?? null) ? $areas[$textArea->value] : [];
            $bag['source'] = AiModelArea::SOURCE_AUTO;
            // Preserve explicit enable from Models Add/drag; never force-enable free.
            if (! array_key_exists('enabled', $bag)) {
                $bag['enabled'] = false;
            }
            $bag['priority'] = (int) ($bag['priority'] ?? $model->priority ?: 100);
            $areas[$textArea->value] = $bag;
        }
        $caps[AiModelPriorityService::AREAS_KEY] = $areas;
        $model->capabilities = $caps;
        $model->save();
    }

    /**
     * @param  array<string, mixed>  $caps
     */
    private function unhideIfFreeChat(SeoAiModel $model, array $caps, string $raw, bool $isOpenRouter): void
    {
        if (! $isOpenRouter || ! OpenRouterModelEconomics::isFree($caps, $raw)) {
            return;
        }
        if (! OpenRouterModelEconomics::isChatTextModel($caps, $raw)) {
            return;
        }
        $model->is_hidden = false;
        $model->status = SeoAiModel::STATUS_ACTIVE;
        $model->save();
    }

    /**
     * @param  array<string, mixed>  $caps
     */
    private function isImageOrVideo(ApiConnection $connection, string $raw, array $caps): bool
    {
        $family = $this->families->familyForModelId($raw);
        if ($family !== null && in_array($family->modality, ['image', 'video'], true)) {
            return true;
        }

        return $this->capabilities->supports($connection, $raw, AiModelCapability::ImageGenerate->value)
            || $this->capabilities->supports($connection, $raw, AiModelCapability::VideoGenerate->value);
    }

    private function looksReasoning(string $hay): bool
    {
        if (preg_match('/(^|[^a-z0-9])(r1|qwq|opus)([^a-z0-9]|$)/', $hay) === 1) {
            return true;
        }

        return str_contains($hay, 'reason')
            || str_contains($hay, 'think');
    }

    private function looksLongform(string $hay, int $qualityTier, int $contextLength): bool
    {
        if ($this->looksFast($hay)) {
            return false;
        }
        if (str_contains($hay, 'nemotron') || str_contains($hay, 'gemma')
            || str_contains($hay, 'llama') || str_contains($hay, 'qwen')
            || str_contains($hay, 'deepseek') || str_contains($hay, 'mistral')
            || str_contains($hay, 'writer') || str_contains($hay, 'long')
            || str_contains($hay, '70b') || str_contains($hay, '120b')
            || str_contains($hay, 'sonnet') || str_contains($hay, 'gpt-oss')) {
            return true;
        }

        return $qualityTier >= 2 && $contextLength >= 32_000;
    }

    private function looksFast(string $hay): bool
    {
        return str_contains($hay, 'nano')
            || str_contains($hay, 'mini')
            || str_contains($hay, 'haiku')
            || str_contains($hay, 'lite')
            || str_contains($hay, 'flash')
            || str_contains($hay, 'small')
            || str_contains($hay, 'instant')
            || str_contains($hay, 'lightning')
            || str_contains($hay, '-xs')
            || str_contains($hay, ' xs');
    }

    /**
     * @return array<int|string, AiModelArea>
     */
    private function routingHintsForUser(int $userId, int $connectionId): array
    {
        $out = [];
        $profiles = AiRoutingProfile::query()
            ->where('user_id', $userId)
            ->whereIn('key', [
                AiExecutionProfile::TextLongform->value,
                AiExecutionProfile::TextReasoning->value,
                AiExecutionProfile::TextFast->value,
            ])
            ->get();

        $priority = [
            AiExecutionProfile::TextLongform->value => AiModelArea::TextLongform,
            AiExecutionProfile::TextReasoning->value => AiModelArea::TextReasoning,
            AiExecutionProfile::TextFast->value => AiModelArea::TextFast,
        ];

        foreach ($profiles as $profile) {
            $area = $priority[(string) $profile->key] ?? null;
            if ($area === null) {
                continue;
            }
            $settings = is_array($profile->settings) ? $profile->settings : [];
            $keys = is_array($settings['allowed_execution_keys'] ?? null) ? $settings['allowed_execution_keys'] : [];
            foreach ($keys as $execKey) {
                if (preg_match('/^(\d+)\|(.+)$/', (string) $execKey, $m) !== 1) {
                    continue;
                }
                if ((int) $m[1] !== $connectionId) {
                    continue;
                }
                $familyKey = (string) $m[2];
                $models = SeoAiModel::query()
                    ->where('api_connection_id', $connectionId)
                    ->get();
                foreach ($models as $model) {
                    $family = $this->families->aggregatorFamily((string) $model->raw_model_name);
                    $synthetic = $family?->familyKey ?? ('openrouter.'.str_replace(['/', ':'], '.', strtolower((string) $model->raw_model_name)));
                    if ($familyKey === ($family?->familyKey ?? '') || $familyKey === $synthetic) {
                        $id = (int) $model->id;
                        if (! isset($out[$id]) || $area === AiModelArea::TextLongform) {
                            $out[$id] = $area;
                            $out[strtolower((string) $model->raw_model_name)] = $area;
                        }
                    }
                }
            }
        }

        return $out;
    }
}
