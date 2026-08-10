<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

final class SeoProviderConnectionStatusService
{
    public function __construct(
        private readonly GoogleSearchConsoleConnectionService $gsc,
        private readonly DataForSeoConnectionService $dataForSeo,
        private readonly SeoSerpProviderConnectionService $serp,
        private readonly SeoExtendedProviderConnectionService $extended,
        private readonly SeoProviderRegistry $registry,
    ) {}

    public function isConfigured(int $userId, string $providerKey): bool
    {
        return match ($providerKey) {
            ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE => $this->gsc->resolveForUser($userId) !== null,
            ApiConnectionProviders::DATAFORSEO => $this->dataForSeo->resolveForUser($userId) !== null
                && filled($this->dataForSeo->resolveForUser($userId)?->login),
            ApiConnectionProviders::SERPAPI,
            ApiConnectionProviders::SERPER,
            ApiConnectionProviders::SEARCHAPI => $this->serp->isConfiguredForUser($userId, $providerKey),
            ApiConnectionProviders::KEYWORDS_EVERYWHERE,
            ApiConnectionProviders::SE_RANKING => $this->extended->isConfiguredForUser($userId, $providerKey),
            default => false,
        };
    }

    public function isActive(int $userId, string $providerKey): bool
    {
        return match ($providerKey) {
            ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE => in_array(
                $this->gsc->resolveEffectiveStatus($this->gsc->resolveForUser($userId) ?? new \Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection),
                ['connected', 'sync_required'],
                true,
            ),
            ApiConnectionProviders::DATAFORSEO => $this->dataForSeo->isConfiguredForUser($userId),
            ApiConnectionProviders::SERPAPI,
            ApiConnectionProviders::SERPER,
            ApiConnectionProviders::SEARCHAPI => $this->serp->isActiveForUser($userId, $providerKey),
            ApiConnectionProviders::KEYWORDS_EVERYWHERE,
            ApiConnectionProviders::SE_RANKING => $this->extended->isActiveForUser($userId, $providerKey),
            default => false,
        };
    }

    public function statusLabel(int $userId, string $providerKey): string
    {
        $status = $this->statusCode($userId, $providerKey);

        return match ($providerKey) {
            ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE => match ($status) {
                'connected' => __('seo-content-ai::filament.api_connections.connected'),
                'mapping_required' => __('seo-content-ai::filament.api_connections.mapping_required'),
                'sync_required' => __('seo-content-ai::filament.api_connections.sync_required'),
                'token_expired' => __('seo-content-ai::filament.api_connections.token_expired'),
                'reauthorization_required' => __('seo-content-ai::filament.api_connections.reauthorization_required'),
                default => __('seo-content-ai::filament.api_connections.not_configured'),
            },
            ApiConnectionProviders::DATAFORSEO => $this->dataForSeo->statusForUser($userId)['label'],
            ApiConnectionProviders::SERPAPI,
            ApiConnectionProviders::SERPER,
            ApiConnectionProviders::SEARCHAPI => $this->serp->statusForUser($userId, $providerKey)['label'],
            ApiConnectionProviders::KEYWORDS_EVERYWHERE,
            ApiConnectionProviders::SE_RANKING => $this->extended->statusForUser($userId, $providerKey)['label'],
            default => __('seo-content-ai::filament.api_connections.not_configured'),
        };
    }

    public function statusCode(int $userId, string $providerKey): string
    {
        return match ($providerKey) {
            ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE => $this->gsc->resolveEffectiveStatus(
                $this->gsc->resolveForUser($userId) ?? new \Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection,
            ),
            ApiConnectionProviders::DATAFORSEO => (string) ($this->dataForSeo->statusForUser($userId)['status'] ?? 'not_configured'),
            ApiConnectionProviders::SERPAPI,
            ApiConnectionProviders::SERPER,
            ApiConnectionProviders::SEARCHAPI => (string) ($this->serp->statusForUser($userId, $providerKey)['status'] ?? 'not_configured'),
            ApiConnectionProviders::KEYWORDS_EVERYWHERE,
            ApiConnectionProviders::SE_RANKING => (string) ($this->extended->statusForUser($userId, $providerKey)['status'] ?? 'not_configured'),
            default => 'not_configured',
        };
    }

    /**
     * @return list<array{key: string, label: string, configured: bool, active: bool, status: string, status_label: string, priority: int}>
     */
    public function performanceTabsForUser(int $userId): array
    {
        $tabs = [];

        $gscDefinition = $this->registry->get(ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE);
        if ($gscDefinition->performanceTabSupported) {
            $tabs[] = [
                'key' => $gscDefinition->sourceKey(),
                'provider' => ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE,
                'label' => $gscDefinition->label,
                'configured' => $this->isConfigured($userId, ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE),
                'active' => $this->isActive($userId, ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE),
                'status' => $this->statusCode($userId, ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE),
                'status_label' => $this->statusLabel($userId, ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE),
                'priority' => $gscDefinition->priority,
            ];
        }

        foreach ($this->registry->seoProviders() as $definition) {
            if ($definition->key === ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE) {
                continue;
            }

            if (! $definition->performanceTabSupported) {
                continue;
            }

            if (! $this->isConfigured($userId, $definition->key)) {
                continue;
            }

            if (! $this->hasUsefulTabState($userId, $definition->key)) {
                continue;
            }

            $tabs[] = [
                'key' => $definition->sourceKey(),
                'provider' => $definition->key,
                'label' => $definition->label,
                'configured' => true,
                'active' => $this->isActive($userId, $definition->key),
                'status' => $this->statusCode($userId, $definition->key),
                'status_label' => $this->statusLabel($userId, $definition->key),
                'priority' => $definition->priority,
            ];
        }

        usort($tabs, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $tabs;
    }

    private function hasUsefulTabState(int $userId, string $providerKey): bool
    {
        $definition = $this->registry->get($providerKey);

        foreach ($definition->dashboardSections as $section) {
            if ($section !== \Omnichannel\Addons\Seo\Enums\PerformanceHubSectionKey::IntegrationState) {
                return true;
            }
        }

        return $definition->partialImplementation && $this->isConfigured($userId, $providerKey);
    }
}
