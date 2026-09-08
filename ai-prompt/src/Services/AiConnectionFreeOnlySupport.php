<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Whether Free only (paid_locked) is meaningful for a connection.
 * OpenRouter always supports free candidates via Free Pool; other providers need catalog free models.
 */
final class AiConnectionFreeOnlySupport
{
    public function supports(ApiConnection $connection): bool
    {
        $provider = (string) $connection->provider;
        if (! ApiConnectionProviders::isAi($provider)) {
            return false;
        }

        if (OpenRouterModelEconomics::isOpenRouterProvider($provider)) {
            return true;
        }

        return $this->hasActiveFreeModel((int) $connection->id);
    }

    public function hasActiveFreeModel(int $connectionId): bool
    {
        if ($connectionId <= 0) {
            return false;
        }

        $models = SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->get();

        foreach ($models as $model) {
            if (OpenRouterModelEconomics::modelIsFree($model)) {
                return true;
            }
        }

        return false;
    }

    public function warningMessage(ApiConnection $connection): ?string
    {
        if (! ApiConnectionProviders::isAi((string) $connection->provider)) {
            return null;
        }

        if ($this->supports($connection)) {
            if ((bool) $connection->paid_locked && ! OpenRouterModelEconomics::isOpenRouterProvider((string) $connection->provider)
                && ! $this->hasActiveFreeModel((int) $connection->id)) {
                return (string) __('seo-content-ai::filament.api_connections.free_only_no_active_free');
            }

            return null;
        }

        return (string) __('seo-content-ai::filament.api_connections.free_only_unsupported');
    }
}
