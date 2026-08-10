<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\Seo\DataTransfer\SeoProviderCapabilityState;
use Omnichannel\Addons\Seo\Enums\SeoProviderCapabilityKey;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

final class SeoProviderCapabilityResolver
{
    public function __construct(
        private readonly SeoProviderRegistry $registry,
        private readonly SeoProviderConnectionStatusService $connectionStatus,
    ) {}

    public function resolve(int $userId, string $providerKey, SeoProviderCapabilityKey $capability): SeoProviderCapabilityState
    {
        if (! $this->registry->has($providerKey)) {
            return SeoProviderCapabilityState::unsupported();
        }

        $definition = $this->registry->get($providerKey);

        if (! $definition->isCapabilitySupported($capability)) {
            return SeoProviderCapabilityState::unsupported();
        }

        if (! $definition->isCapabilityImplemented($capability)) {
            return SeoProviderCapabilityState::vendorOnly();
        }

        if (! $this->connectionStatus->isConfigured($userId, $providerKey)) {
            return SeoProviderCapabilityState::notConfigured();
        }

        return SeoProviderCapabilityState::available();
    }

    /**
     * @return array{state: string, label: string, accessible_label: string}
     */
    public function integrationState(int $userId, string $providerKey): array
    {
        $definition = $this->registry->get($providerKey);
        $configured = $this->connectionStatus->isConfigured($userId, $providerKey);
        $hasImplemented = false;

        foreach (SeoProviderCapabilityKey::cases() as $capability) {
            if ($definition->isCapabilityImplemented($capability)) {
                $hasImplemented = true;
                break;
            }
        }

        if (! $configured) {
            return [
                'state' => 'not_configured',
                'label' => __('seo-content-ai::filament.api_connections.integration_not_configured'),
                'accessible_label' => __('seo-content-ai::filament.api_connections.integration_not_configured_a11y', [
                    'provider' => $definition->label,
                ]),
            ];
        }

        if (! $hasImplemented) {
            return [
                'state' => 'partial_implementation',
                'label' => __('seo-content-ai::filament.api_connections.integration_partial'),
                'accessible_label' => __('seo-content-ai::filament.api_connections.integration_partial_a11y', [
                    'provider' => $definition->label,
                ]),
            ];
        }

        if ($this->connectionStatus->isActive($userId, $providerKey)) {
            return [
                'state' => 'connected',
                'label' => __('seo-content-ai::filament.api_connections.integration_connected'),
                'accessible_label' => __('seo-content-ai::filament.api_connections.integration_connected_a11y', [
                    'provider' => $definition->label,
                ]),
            ];
        }

        return [
            'state' => 'connected',
            'label' => __('seo-content-ai::filament.api_connections.integration_connected'),
            'accessible_label' => __('seo-content-ai::filament.api_connections.integration_connected_a11y', [
                'provider' => $definition->label,
            ]),
        ];
    }

    /**
     * Matrix cell display state: available | vendor_only | unsupported | not_configured
     */
    public function matrixCellState(SeoProviderCapabilityState $state): string
    {
        if (! $state->supported) {
            return 'unsupported';
        }

        if (! $state->implemented) {
            return 'vendor_only';
        }

        if (! $state->configured) {
            return 'not_configured';
        }

        return 'available';
    }

    public function canDispatchAction(int $userId, string $providerKey, string $action): bool
    {
        return in_array($action, $this->registry->availableActionsFor($providerKey), true)
            && $this->connectionStatus->isActive($userId, $providerKey);
    }

    public function canDispatchMetric(int $userId, string $providerKey, string $metric): bool
    {
        $capability = match ($metric) {
            'rank' => SeoProviderCapabilityKey::RankTracking,
            'allintitle' => SeoProviderCapabilityKey::Allintitle,
            'search_volume' => SeoProviderCapabilityKey::SearchVolume,
            default => null,
        };

        if ($capability === null) {
            return false;
        }

        $state = $this->resolve($userId, $providerKey, $capability);

        if ($metric === 'search_volume' && $providerKey === ApiConnectionProviders::DATAFORSEO) {
            return $this->connectionStatus->isConfigured($userId, $providerKey);
        }

        return $state->available;
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    public function filterDispatchableMetrics(int $userId, string $providerKey, array $requested): array
    {
        $allowed = [];

        foreach ($requested as $metric) {
            if ($this->canDispatchMetric($userId, $providerKey, $metric)) {
                $allowed[] = $metric;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @return array{rank: bool, allintitle: bool, search_volume: bool, search_volume_configured: bool}
     */
    public function legacyToolbarCapabilities(int $userId, string $providerKey): array
    {
        if (! $this->registry->has($providerKey)) {
            return [
                'rank' => false,
                'allintitle' => false,
                'search_volume' => false,
                'search_volume_configured' => false,
            ];
        }

        $definition = $this->registry->get($providerKey);

        return [
            'rank' => $definition->isCapabilityImplemented(SeoProviderCapabilityKey::RankTracking),
            'allintitle' => $definition->isCapabilityImplemented(SeoProviderCapabilityKey::Allintitle),
            'search_volume' => false,
            'search_volume_configured' => $this->connectionStatus->isConfigured($userId, ApiConnectionProviders::DATAFORSEO),
        ];
    }
}
