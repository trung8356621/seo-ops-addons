<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelCatalogAuthorityMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves catalog authority + invokes the canonical sync entry
 * ({@see AiModelRouterService::syncModelsForConnection()}).
 */
final class AiProviderModelCatalogGateway
{
    public function __construct(
        private readonly mixed $router = null,
    ) {}

    public function supportsDiscovery(ApiConnection $connection): bool
    {
        return match ((string) $connection->provider) {
            ApiConnectionProviders::OPENROUTER,
            ApiConnectionProviders::DEEPSEEK,
            ApiConnectionProviders::GEMINI,
            ApiConnectionProviders::CLAUDE,
            'openai_compatible' => true,
            default => false,
        };
    }

    public function authorityMode(ApiConnection $connection): AiModelCatalogAuthorityMode
    {
        return match ((string) $connection->provider) {
            ApiConnectionProviders::OPENROUTER,
            ApiConnectionProviders::DEEPSEEK,
            ApiConnectionProviders::CLAUDE,
            'openai_compatible' => AiModelCatalogAuthorityMode::Provider,
            // Gemini: /models omits some image models → curated image layer + provider text.
            ApiConnectionProviders::GEMINI => AiModelCatalogAuthorityMode::Hybrid,
            default => AiModelCatalogAuthorityMode::Curated,
        };
    }

    public function sync(ApiConnection $connection, bool $resolveFromContainer = false): bool
    {
        $router = $this->router;
        if ($router === null && function_exists('app')
            && ($resolveFromContainer || app()->bound(AiModelRouterService::class))) {
            try {
                $router = app(AiModelRouterService::class);
            } catch (\Throwable) {
                $router = null;
            }
        }
        if (! is_object($router) || ! method_exists($router, 'syncModelsForConnection')) {
            return false;
        }

        return (bool) $router->syncModelsForConnection((int) $connection->id);
    }

    /**
     * @return array{active: int, total: int}
     */
    public function inventoryCounts(ApiConnection $connection): array
    {
        $cid = (int) $connection->id;
        if ($cid <= 0) {
            return ['active' => 0, 'total' => 0];
        }

        $total = (int) SeoAiModel::query()->where('api_connection_id', $cid)->count();
        $active = (int) SeoAiModel::query()
            ->where('api_connection_id', $cid)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->count();

        return ['active' => $active, 'total' => $total];
    }
}
