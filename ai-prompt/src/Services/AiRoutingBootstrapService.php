<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use App\Models\ApiConnection;

/**
 * Seeds routing targets from currently active compatible models so existing
 * Gemini installs keep working after migration.
 */
final class AiRoutingBootstrapService
{
    public function __construct(
        private readonly ModelCapabilityRegistry $capabilities,
        private readonly AiRoutingTargetService $targets,
    ) {}

    public function bootstrapForUser(int $userId): void
    {
        foreach (AiExecutionProfile::cases() as $profile) {
            if ($this->targets->targetsFor($userId, $profile->value) !== []) {
                continue;
            }

            $rows = $this->discoverRows($userId, $profile);
            if ($rows === []) {
                continue;
            }

            $this->targets->replaceTargets($userId, $profile->value, $rows);
            $this->targets->writeProfileSettings($userId, $profile, [
                'usage_mode' => AiUsageMode::defaultForProfile($profile)->value,
                'allowed_family_keys' => [],
                'preserve_explicit_order' => true,
                'simplified' => false,
            ]);
        }
    }

    /**
     * @return list<array{api_connection_id: int, model_key: string}>
     */
    private function discoverRows(int $userId, AiExecutionProfile $profile): array
    {
        $connections = ApiConnection::query()
            ->where('status', 'active')
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('is_global', true);
            })
            ->get();

        $rows = [];
        foreach ($connections as $connection) {
            if ($profile->isMedia() && (string) $connection->provider === ApiConnectionProviders::DEEPSEEK) {
                continue;
            }

            $models = SeoAiModel::query()
                ->where('api_connection_id', $connection->id)
                ->where('status', SeoAiModel::STATUS_ACTIVE)
                ->when(
                    \Illuminate\Support\Facades\Schema::hasColumn('seo_ai_models', 'is_hidden'),
                    static fn ($query) => $query->where('is_hidden', false),
                )
                ->orderByDesc('priority')
                ->get();

            foreach ($models as $model) {
                $key = (string) $model->raw_model_name;
                if (! $this->capabilities->satisfiesAll($connection, $key, $profile->requiredCapabilityKeys())) {
                    continue;
                }
                $rows[] = [
                    'api_connection_id' => (int) $connection->id,
                    'model_key' => $key,
                ];
            }
        }

        return $rows;
    }
}
