<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\DataTransfer;

use Omnichannel\Addons\AiPrompt\Enums\ApiConnectionType;
use Omnichannel\Addons\Seo\Enums\PerformanceHubSectionKey;
use Omnichannel\Addons\Seo\Enums\SeoProviderCapabilityKey;
use Omnichannel\Addons\Seo\Enums\SeoProviderCategory;

final readonly class SeoProviderDefinition
{
    /**
     * @param  array<SeoProviderCapabilityKey, bool>  $supportedCapabilities
     * @param  array<SeoProviderCapabilityKey, bool>  $implementedCapabilities
     * @param  list<PerformanceHubSectionKey>  $dashboardSections
     * @param  list<string>  $dashboardActions
     * @param  list<string>  $requiredCredentials
     */
    public function __construct(
        public string $key,
        public string $label,
        public ApiConnectionType $connectionType,
        public SeoProviderCategory $category,
        public string $description,
        public ?string $documentationUrl,
        public int $priority,
        public bool $settingsSupported,
        public bool $performanceTabSupported,
        public array $supportedCapabilities,
        public array $implementedCapabilities,
        public array $dashboardSections,
        public array $dashboardActions,
        public array $requiredCredentials,
        public ?string $bestFor,
        public ?string $adapterClass = null,
        public bool $partialImplementation = false,
        public ?string $performanceSourceKey = null,
    ) {}

    public function sourceKey(): string
    {
        return $this->performanceSourceKey ?? $this->key;
    }

    public function isCapabilitySupported(SeoProviderCapabilityKey $capability): bool
    {
        return ($this->supportedCapabilities[$capability->value] ?? false) === true;
    }

    public function isCapabilityImplemented(SeoProviderCapabilityKey $capability): bool
    {
        return ($this->implementedCapabilities[$capability->value] ?? false) === true;
    }

    public function supportsDashboardSection(PerformanceHubSectionKey $section): bool
    {
        return in_array($section, $this->dashboardSections, true);
    }
}
