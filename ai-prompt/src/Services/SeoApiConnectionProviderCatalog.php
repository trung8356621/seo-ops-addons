<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\Seo\DataTransfer\SeoProviderCapabilityState;
use Omnichannel\Addons\Seo\DataTransfer\SeoProviderDefinition;
use Omnichannel\Addons\AiPrompt\Enums\ApiConnectionType;
use Omnichannel\Addons\Seo\Enums\PerformanceHubSectionKey;
use Omnichannel\Addons\Seo\Enums\SeoProviderCapabilityKey;
use Omnichannel\Addons\Seo\Enums\SeoProviderCategory;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SearchApiProvider;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SerpApiProvider;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SerperDevProvider;
use Omnichannel\Addons\SearchIntelligence\Services\SeoProviderCapabilityResolver;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

class SeoApiConnectionProviderCatalog
{
    /** @var array<string, SeoProviderDefinition>|null */
    private ?array $definitions = null;

    public function has(string $key): bool
    {
        return isset($this->definitions()[$key]);
    }

    public function get(string $key): SeoProviderDefinition
    {
        $definition = $this->definitions()[$key] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown SEO provider: {$key}");
        }

        return $definition;
    }

    /**
     * @return array<string, SeoProviderDefinition>
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $this->definitions = [
            ApiConnectionProviders::GEMINI => $this->geminiDefinition(),
            ApiConnectionProviders::CLAUDE => $this->claudeDefinition(),
            ApiConnectionProviders::DEEPSEEK => $this->deepseekDefinition(),
            ApiConnectionProviders::OPENROUTER => $this->openrouterDefinition(),
            ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE => $this->gscDefinition(),
            ApiConnectionProviders::DATAFORSEO => $this->dataForSeoDefinition(),
            ApiConnectionProviders::SERPAPI => $this->serpApiDefinition(),
            ApiConnectionProviders::SERPER => $this->serperDefinition(),
            ApiConnectionProviders::SEARCHAPI => $this->searchApiDefinition(),
            ApiConnectionProviders::KEYWORDS_EVERYWHERE => $this->keywordsEverywhereDefinition(),
            ApiConnectionProviders::SE_RANKING => $this->seRankingDefinition(),
        ];

        return $this->definitions;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return list<SeoProviderDefinition>
     */
    public function seoProviders(): array
    {
        return array_values(array_filter(
            $this->definitions(),
            static fn (SeoProviderDefinition $definition): bool => $definition->connectionType === ApiConnectionType::Seo,
        ));
    }

    /**
     * @return list<SeoProviderDefinition>
     */
    public function settingsProviders(): array
    {
        return array_values(array_filter(
            $this->definitions(),
            static fn (SeoProviderDefinition $definition): bool => $definition->settingsSupported,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function groupedProviderOptions(): array
    {
        $ai = [];
        $seo = [];

        foreach ($this->settingsProviders() as $definition) {
            if ($definition->connectionType === ApiConnectionType::Ai) {
                $ai[$definition->key] = $definition->label;
            } else {
                $seo[$definition->key] = $definition->label;
            }
        }

        return [
            __('seo-content-ai::filament.api_connections.group_ai_providers') => $ai,
            __('seo-content-ai::filament.api_connections.group_seo_providers') => $seo,
        ];
    }

    public function connectionTypeFor(string $provider): ApiConnectionType
    {
        return $this->get($provider)->connectionType;
    }

    public function label(string $provider): string
    {
        return $this->get($provider)->label;
    }

    /**
     * @return list<SeoProviderCapabilityKey>
     */
    public function matrixCapabilityColumns(): array
    {
        return [
            SeoProviderCapabilityKey::RankTracking,
            SeoProviderCapabilityKey::LiveSerp,
            SeoProviderCapabilityKey::SerpChanges,
            SeoProviderCapabilityKey::Allintitle,
            SeoProviderCapabilityKey::SearchVolume,
            SeoProviderCapabilityKey::Cpc,
            SeoProviderCapabilityKey::Competition,
            SeoProviderCapabilityKey::MonthlyTrend,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function capabilityMatrixRows(int $userId, SeoProviderCapabilityResolver $resolver): array
    {
        $rows = [];

        foreach ($this->seoProviders() as $definition) {
            if (! $definition->settingsSupported) {
                continue;
            }

            $capabilities = [];
            foreach ($this->matrixCapabilityColumns() as $capability) {
                $capabilities[$capability->value] = $resolver->resolve($userId, $definition->key, $capability);
            }

            $integration = $resolver->integrationState($userId, $definition->key);

            $rows[] = [
                'key' => $definition->key,
                'label' => $definition->label,
                'priority' => $definition->priority,
                'best_for' => $definition->bestFor,
                'capabilities' => $capabilities,
                'integration' => $integration,
                'partial_implementation' => $definition->partialImplementation,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $rows;
    }

    /**
     * @return list<PerformanceHubSectionKey>
     */
    public function dashboardSectionsFor(string $providerKey): array
    {
        return $this->get($providerKey)->dashboardSections;
    }

    /**
     * @return list<string>
     */
    public function availableActionsFor(string $providerKey): array
    {
        return $this->get($providerKey)->dashboardActions;
    }

    public function performanceTabSupported(string $providerKey): bool
    {
        return $this->get($providerKey)->performanceTabSupported;
    }

    public function resolveProviderFromSource(string $source): ?string
    {
        foreach ($this->definitions() as $definition) {
            if ($definition->sourceKey() === $source) {
                return $definition->key;
            }
        }

        return null;
    }

    public function sourceKeyFor(string $providerKey): string
    {
        return $this->get($providerKey)->sourceKey();
    }

    public function isPerformanceSource(string $source): bool
    {
        return $this->resolveProviderFromSource($source) !== null;
    }

    public function isRawSerpSource(string $source): bool
    {
        $provider = $this->resolveProviderFromSource($source);

        return $provider !== null && $this->isRawSerpProvider($provider);
    }

    public function isRawSerpProvider(string $providerKey): bool
    {
        return in_array($providerKey, [
            ApiConnectionProviders::SERPAPI,
            ApiConnectionProviders::SERPER,
            ApiConnectionProviders::SEARCHAPI,
        ], true);
    }

    public function isRankCompatibleProvider(string $providerKey): bool
    {
        $definition = $this->get($providerKey);

        return $definition->isCapabilityImplemented(SeoProviderCapabilityKey::RankTracking)
            && $definition->isCapabilitySupported(SeoProviderCapabilityKey::RankTracking)
            && $this->isRawSerpProvider($providerKey);
    }

    /**
     * @param  array<SeoProviderCapabilityKey, bool>  $flags
     * @return array<string, bool>
     */
    private function capabilityMap(array $flags): array
    {
        $map = [];
        foreach (SeoProviderCapabilityKey::cases() as $capability) {
            $map[$capability->value] = false;
        }

        foreach ($flags as $capability => $value) {
            $key = $capability instanceof SeoProviderCapabilityKey ? $capability->value : (string) $capability;
            $map[$key] = (bool) $value;
        }

        return $map;
    }

    private function geminiDefinition(): SeoProviderDefinition
    {
        return new SeoProviderDefinition(
            key: ApiConnectionProviders::GEMINI,
            label: __('seo-content-ai::filament.api_connections.provider_gemini'),
            connectionType: ApiConnectionType::Ai,
            category: SeoProviderCategory::SeoSuite,
            description: __('seo-content-ai::filament.api_connections.provider_gemini_desc'),
            documentationUrl: 'https://aistudio.google.com/app/apikey',
            priority: 10,
            settingsSupported: true,
            performanceTabSupported: false,
            supportedCapabilities: [],
            implementedCapabilities: [],
            dashboardSections: [],
            dashboardActions: [],
            requiredCredentials: ['api_key'],
            bestFor: null,
        );
    }

    private function claudeDefinition(): SeoProviderDefinition
    {
        return new SeoProviderDefinition(
            key: ApiConnectionProviders::CLAUDE,
            label: __('seo-content-ai::filament.api_connections.provider_claude'),
            connectionType: ApiConnectionType::Ai,
            category: SeoProviderCategory::SeoSuite,
            description: __('seo-content-ai::filament.api_connections.provider_claude_desc'),
            documentationUrl: 'https://console.anthropic.com/settings/keys',
            priority: 20,
            settingsSupported: true,
            performanceTabSupported: false,
            supportedCapabilities: [],
            implementedCapabilities: [],
            dashboardSections: [],
            dashboardActions: [],
            requiredCredentials: ['api_key'],
            bestFor: null,
        );
    }

    private function deepseekDefinition(): SeoProviderDefinition
    {
        return new SeoProviderDefinition(
            key: ApiConnectionProviders::DEEPSEEK,
            label: __('seo-content-ai::filament.api_connections.provider_deepseek'),
            connectionType: ApiConnectionType::Ai,
            category: SeoProviderCategory::SeoSuite,
            description: __('seo-content-ai::filament.api_connections.provider_deepseek_desc'),
            documentationUrl: 'https://platform.deepseek.com/api_keys',
            priority: 15,
            settingsSupported: true,
            performanceTabSupported: false,
            supportedCapabilities: [],
            implementedCapabilities: [],
            dashboardSections: [],
            dashboardActions: [],
            requiredCredentials: ['api_key'],
            bestFor: null,
        );
    }

    private function openrouterDefinition(): SeoProviderDefinition
    {
        return new SeoProviderDefinition(
            key: ApiConnectionProviders::OPENROUTER,
            label: __('seo-content-ai::filament.api_connections.provider_openrouter'),
            connectionType: ApiConnectionType::Ai,
            category: SeoProviderCategory::SeoSuite,
            description: __('seo-content-ai::filament.api_connections.provider_openrouter_desc'),
            documentationUrl: 'https://openrouter.ai/keys',
            priority: 16,
            settingsSupported: true,
            performanceTabSupported: false,
            supportedCapabilities: [],
            implementedCapabilities: [],
            dashboardSections: [],
            dashboardActions: [],
            requiredCredentials: ['api_key'],
            bestFor: null,
        );
    }

    private function gscDefinition(): SeoProviderDefinition
    {
        $supported = $this->capabilityMap([
            SeoProviderCapabilityKey::GscPerformance->value => true,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
            SeoProviderCapabilityKey::Device->value => true,
        ]);
        $implemented = $this->capabilityMap([
            SeoProviderCapabilityKey::GscPerformance->value => true,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
            SeoProviderCapabilityKey::Device->value => true,
        ]);

        return new SeoProviderDefinition(
            key: ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE,
            label: __('seo-content-ai::filament.api_connections.provider_gsc'),
            connectionType: ApiConnectionType::Seo,
            category: SeoProviderCategory::SearchConsole,
            description: __('seo-content-ai::filament.api_connections.provider_gsc_desc'),
            documentationUrl: 'https://developers.google.com/webmaster-tools',
            priority: 5,
            settingsSupported: true,
            performanceTabSupported: true,
            supportedCapabilities: $supported,
            implementedCapabilities: $implemented,
            dashboardSections: [
                PerformanceHubSectionKey::GscKpis,
                PerformanceHubSectionKey::GscChart,
                PerformanceHubSectionKey::GscQueries,
            ],
            dashboardActions: ['sync_gsc'],
            requiredCredentials: ['oauth_client_id', 'oauth_client_secret'],
            bestFor: __('seo-content-ai::filament.api_connections.best_for_gsc'),
            performanceSourceKey: 'gsc',
        );
    }

    private function dataForSeoDefinition(): SeoProviderDefinition
    {
        $supported = $this->capabilityMap([
            SeoProviderCapabilityKey::RankTracking->value => true,
            SeoProviderCapabilityKey::LiveSerp->value => true,
            SeoProviderCapabilityKey::SerpChanges->value => true,
            SeoProviderCapabilityKey::Allintitle->value => true,
            SeoProviderCapabilityKey::SearchVolume->value => true,
            SeoProviderCapabilityKey::Cpc->value => true,
            SeoProviderCapabilityKey::Competition->value => true,
            SeoProviderCapabilityKey::MonthlyTrend->value => true,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
        ]);
        $implemented = $this->capabilityMap([
            SeoProviderCapabilityKey::SearchVolume->value => true,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
        ]);

        return new SeoProviderDefinition(
            key: ApiConnectionProviders::DATAFORSEO,
            label: __('seo-content-ai::filament.api_connections.provider_dataforseo'),
            connectionType: ApiConnectionType::Seo,
            category: SeoProviderCategory::SeoSuite,
            description: __('seo-content-ai::filament.api_connections.provider_dataforseo_desc'),
            documentationUrl: 'https://docs.dataforseo.com/',
            priority: 90,
            settingsSupported: true,
            performanceTabSupported: false,
            supportedCapabilities: $supported,
            implementedCapabilities: $implemented,
            dashboardSections: [],
            dashboardActions: [],
            requiredCredentials: ['login', 'password'],
            bestFor: __('seo-content-ai::filament.api_connections.best_for_dataforseo'),
            partialImplementation: true,
        );
    }

    private function serpApiDefinition(): SeoProviderDefinition
    {
        $supported = $this->capabilityMap([
            SeoProviderCapabilityKey::RankTracking->value => true,
            SeoProviderCapabilityKey::LiveSerp->value => true,
            SeoProviderCapabilityKey::SerpChanges->value => true,
            SeoProviderCapabilityKey::OrganicVisibility->value => true,
            SeoProviderCapabilityKey::ProviderComparison->value => true,
            SeoProviderCapabilityKey::Allintitle->value => true,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
            SeoProviderCapabilityKey::Device->value => true,
            SeoProviderCapabilityKey::TargetDomain->value => true,
        ]);
        $implemented = $supported;

        return new SeoProviderDefinition(
            key: ApiConnectionProviders::SERPAPI,
            label: __('seo-content-ai::filament.api_connections.provider_serpapi'),
            connectionType: ApiConnectionType::Seo,
            category: SeoProviderCategory::Serp,
            description: __('seo-content-ai::filament.api_connections.provider_serpapi_desc'),
            documentationUrl: 'https://serpapi.com/search-api',
            priority: 30,
            settingsSupported: true,
            performanceTabSupported: true,
            supportedCapabilities: $supported,
            implementedCapabilities: $implemented,
            dashboardSections: [
                PerformanceHubSectionKey::RankKpis,
                PerformanceHubSectionKey::RankDistribution,
                PerformanceHubSectionKey::RankingsTable,
                PerformanceHubSectionKey::AllintitleMetric,
                PerformanceHubSectionKey::SerpChanges,
                PerformanceHubSectionKey::OrganicVisibility,
                PerformanceHubSectionKey::ProviderComparison,
            ],
            dashboardActions: ['check_rank', 'check_allintitle'],
            requiredCredentials: ['api_key'],
            bestFor: __('seo-content-ai::filament.api_connections.best_for_serpapi'),
            adapterClass: SerpApiProvider::class,
        );
    }

    private function serperDefinition(): SeoProviderDefinition
    {
        $supported = $this->capabilityMap([
            SeoProviderCapabilityKey::RankTracking->value => true,
            SeoProviderCapabilityKey::LiveSerp->value => true,
            SeoProviderCapabilityKey::SerpChanges->value => true,
            SeoProviderCapabilityKey::OrganicVisibility->value => true,
            SeoProviderCapabilityKey::ProviderComparison->value => true,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
            SeoProviderCapabilityKey::Device->value => true,
            SeoProviderCapabilityKey::TargetDomain->value => true,
        ]);
        $implemented = $this->capabilityMap([
            SeoProviderCapabilityKey::RankTracking->value => true,
            SeoProviderCapabilityKey::LiveSerp->value => true,
            SeoProviderCapabilityKey::SerpChanges->value => true,
            SeoProviderCapabilityKey::OrganicVisibility->value => true,
            SeoProviderCapabilityKey::ProviderComparison->value => true,
            SeoProviderCapabilityKey::Allintitle->value => false,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
            SeoProviderCapabilityKey::Device->value => true,
            SeoProviderCapabilityKey::TargetDomain->value => true,
        ]);

        return new SeoProviderDefinition(
            key: ApiConnectionProviders::SERPER,
            label: __('seo-content-ai::filament.api_connections.provider_serper'),
            connectionType: ApiConnectionType::Seo,
            category: SeoProviderCategory::Serp,
            description: __('seo-content-ai::filament.api_connections.provider_serper_desc'),
            documentationUrl: 'https://serper.dev/',
            priority: 40,
            settingsSupported: true,
            performanceTabSupported: true,
            supportedCapabilities: $supported,
            implementedCapabilities: $implemented,
            dashboardSections: [
                PerformanceHubSectionKey::RankKpis,
                PerformanceHubSectionKey::RankDistribution,
                PerformanceHubSectionKey::RankingsTable,
                PerformanceHubSectionKey::SerpChanges,
                PerformanceHubSectionKey::OrganicVisibility,
                PerformanceHubSectionKey::ProviderComparison,
            ],
            dashboardActions: ['check_rank'],
            requiredCredentials: ['api_key'],
            bestFor: __('seo-content-ai::filament.api_connections.best_for_serper'),
            adapterClass: SerperDevProvider::class,
        );
    }

    private function searchApiDefinition(): SeoProviderDefinition
    {
        $supported = $this->capabilityMap([
            SeoProviderCapabilityKey::RankTracking->value => true,
            SeoProviderCapabilityKey::LiveSerp->value => true,
            SeoProviderCapabilityKey::SerpChanges->value => true,
            SeoProviderCapabilityKey::OrganicVisibility->value => true,
            SeoProviderCapabilityKey::ProviderComparison->value => true,
            SeoProviderCapabilityKey::Allintitle->value => true,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
            SeoProviderCapabilityKey::Device->value => true,
            SeoProviderCapabilityKey::TargetDomain->value => true,
        ]);
        $implemented = $supported;

        return new SeoProviderDefinition(
            key: ApiConnectionProviders::SEARCHAPI,
            label: __('seo-content-ai::filament.api_connections.provider_searchapi'),
            connectionType: ApiConnectionType::Seo,
            category: SeoProviderCategory::Serp,
            description: __('seo-content-ai::filament.api_connections.provider_searchapi_desc'),
            documentationUrl: 'https://www.searchapi.io/docs',
            priority: 50,
            settingsSupported: true,
            performanceTabSupported: true,
            supportedCapabilities: $supported,
            implementedCapabilities: $implemented,
            dashboardSections: [
                PerformanceHubSectionKey::RankKpis,
                PerformanceHubSectionKey::RankDistribution,
                PerformanceHubSectionKey::RankingsTable,
                PerformanceHubSectionKey::AllintitleMetric,
                PerformanceHubSectionKey::SerpChanges,
                PerformanceHubSectionKey::OrganicVisibility,
                PerformanceHubSectionKey::ProviderComparison,
            ],
            dashboardActions: ['check_rank', 'check_allintitle'],
            requiredCredentials: ['api_key'],
            bestFor: __('seo-content-ai::filament.api_connections.best_for_searchapi'),
            adapterClass: SearchApiProvider::class,
        );
    }

    private function keywordsEverywhereDefinition(): SeoProviderDefinition
    {
        $supported = $this->capabilityMap([
            SeoProviderCapabilityKey::SearchVolume->value => true,
            SeoProviderCapabilityKey::Cpc->value => true,
            SeoProviderCapabilityKey::Competition->value => true,
            SeoProviderCapabilityKey::MonthlyTrend->value => true,
            SeoProviderCapabilityKey::RelatedKeywords->value => true,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
        ]);
        $implemented = $this->capabilityMap([]);

        return new SeoProviderDefinition(
            key: ApiConnectionProviders::KEYWORDS_EVERYWHERE,
            label: __('seo-content-ai::filament.api_connections.provider_keywords_everywhere'),
            connectionType: ApiConnectionType::Seo,
            category: SeoProviderCategory::KeywordMetrics,
            description: __('seo-content-ai::filament.api_connections.provider_keywords_everywhere_desc'),
            documentationUrl: 'https://keywordseverywhere.com/api-documentation',
            priority: 60,
            settingsSupported: true,
            performanceTabSupported: true,
            supportedCapabilities: $supported,
            implementedCapabilities: $implemented,
            dashboardSections: [
                PerformanceHubSectionKey::KeywordMetricsTable,
                PerformanceHubSectionKey::KeywordTrend,
                PerformanceHubSectionKey::IntegrationState,
            ],
            dashboardActions: ['fetch_keyword_metrics'],
            requiredCredentials: ['api_key'],
            bestFor: __('seo-content-ai::filament.api_connections.best_for_keywords_everywhere'),
            partialImplementation: true,
        );
    }

    private function seRankingDefinition(): SeoProviderDefinition
    {
        $supported = $this->capabilityMap([
            SeoProviderCapabilityKey::RankTracking->value => true,
            SeoProviderCapabilityKey::RankHistory->value => true,
            SeoProviderCapabilityKey::SerpChanges->value => true,
            SeoProviderCapabilityKey::OrganicVisibility->value => true,
            SeoProviderCapabilityKey::SearchVolume->value => true,
            SeoProviderCapabilityKey::Cpc->value => true,
            SeoProviderCapabilityKey::Competition->value => true,
            SeoProviderCapabilityKey::KeywordDifficulty->value => true,
            SeoProviderCapabilityKey::MonthlyTrend->value => true,
            SeoProviderCapabilityKey::Location->value => true,
            SeoProviderCapabilityKey::Language->value => true,
            SeoProviderCapabilityKey::TargetDomain->value => true,
        ]);
        $implemented = $this->capabilityMap([]);

        return new SeoProviderDefinition(
            key: ApiConnectionProviders::SE_RANKING,
            label: __('seo-content-ai::filament.api_connections.provider_seranking'),
            connectionType: ApiConnectionType::Seo,
            category: SeoProviderCategory::RankPlatform,
            description: __('seo-content-ai::filament.api_connections.provider_seranking_desc'),
            documentationUrl: 'https://seranking.com/api.html',
            priority: 70,
            settingsSupported: true,
            performanceTabSupported: false,
            supportedCapabilities: $supported,
            implementedCapabilities: $implemented,
            dashboardSections: [
                PerformanceHubSectionKey::IntegrationState,
            ],
            dashboardActions: [],
            requiredCredentials: ['api_token'],
            bestFor: __('seo-content-ai::filament.api_connections.best_for_seranking'),
            partialImplementation: true,
        );
    }
}
