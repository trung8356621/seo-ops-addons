<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Restores global text routing after free-only was incorrectly merged into profile settings.
 * Does not delete inventory models.
 */
final class AiFreeOnlyRoutingConfigurator
{
    public function __construct(
        private readonly AiRoutingTargetService $targets = new AiRoutingTargetService(new ModelCapabilityRegistry()),
        private readonly AiModelFamilyCatalog $families = new AiModelFamilyCatalog(),
    ) {}

    /**
     * @return array{stripped_keys: array<string, list<string>>}
     */
    public function restoreGlobalRouting(int $userId): array
    {
        $stripped = [];
        foreach ([
            AiExecutionProfile::TextFast,
            AiExecutionProfile::TextLongform,
            AiExecutionProfile::TextReasoning,
        ] as $profile) {
            $stripped[$profile->value] = $this->stripAutoMergedFreeKeys($userId, $profile);
        }
        $this->targets->forgetMemo();

        return ['stripped_keys' => $stripped];
    }

    /**
     * @return list<string>
     */
    private function stripAutoMergedFreeKeys(int $userId, AiExecutionProfile $profile): array
    {
        $settings = $this->targets->profileSettings($userId, $profile);
        unset($settings[AiRoutingTargetService::SETTING_ALLOW_PAID_FALLBACK]);
        $execution = is_array($settings['allowed_execution_keys'] ?? null) ? $settings['allowed_execution_keys'] : [];
        $families = is_array($settings['allowed_family_keys'] ?? null) ? $settings['allowed_family_keys'] : [];
        $automatic = $execution === [] && ($families === [] || in_array(AiModelFamilyCatalog::AUTOMATIC, $families, true));
        if ($automatic) {
            $this->targets->overwriteProfileSettings($userId, $profile, $settings);

            return [];
        }

        $kept = [];
        $removed = [];
        foreach ($execution as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            if ($this->isPureOpenRouterFreeExecutionKey($userId, $key)) {
                $removed[] = $key;
                continue;
            }
            $kept[] = $key;
        }

        $familyKeys = [];
        foreach ($kept as $execKey) {
            if (preg_match('/^\d+\|(.+)$/', $execKey, $matches) === 1) {
                $familyKeys[] = $matches[1];
            }
        }
        $settings['allowed_execution_keys'] = array_values(array_unique($kept));
        $settings['allowed_family_keys'] = array_values(array_unique($familyKeys));
        $this->targets->overwriteProfileSettings($userId, $profile, $settings);

        return array_values(array_unique($removed));
    }

    private function isPureOpenRouterFreeExecutionKey(int $userId, string $execKey): bool
    {
        if (preg_match('/^(\d+)\|(.+)$/', $execKey, $matches) !== 1) {
            return false;
        }
        $connectionId = (int) $matches[1];
        $familyKey = (string) $matches[2];
        $connection = ApiConnection::query()->find($connectionId);
        if (! $connection instanceof ApiConnection) {
            return $familyKey === 'openrouter.free';
        }
        if ((string) $connection->provider !== ApiConnectionProviders::OPENROUTER) {
            return false;
        }
        if ((int) $connection->user_id !== $userId && ! (bool) $connection->is_global) {
            return false;
        }
        if ($familyKey === 'openrouter.free') {
            return true;
        }

        $models = SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->get();
        $matched = 0;
        foreach ($models as $model) {
            $family = $this->families->aggregatorFamily((string) $model->raw_model_name);
            if ($family === null || $family->familyKey !== $familyKey) {
                continue;
            }
            $matched++;
            if (! OpenRouterModelEconomics::modelIsFree($model)) {
                return false;
            }
        }

        return $matched > 0;
    }
}
