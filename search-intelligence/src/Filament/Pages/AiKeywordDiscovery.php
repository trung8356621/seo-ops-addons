<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;


use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\SearchIntelligence\Filament\Concerns\InteractsWithAiKeywordDiscovery;
use Omnichannel\Addons\SearchIntelligence\Services\AiKeywordDiscoveryService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Contracts\Support\Htmlable;

final class AiKeywordDiscovery extends SeoPanelPage
{
    use InteractsWithAiKeywordDiscovery;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = 'Keyword Intelligence';

    protected static ?string $navigationLabel = 'AI Keyword Discovery';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'keywords/ai-discovery';

    protected static string $view = 'seo-content-ai::seo.ai-keyword-discovery';

    protected static bool $shouldRegisterNavigation = true;

    public function boot(
        AiKeywordDiscoveryService $discovery,
        KeywordPersistenceService $keywordPersistence,
        CreateArticlesFromTaskService $articleCreator,
    ): void {
        $this->bootAiKeywordDiscovery($discovery, $keywordPersistence, $articleCreator);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.keyword.ai_discovery_nav');
    }

    public static function getNavigationParentItem(): ?string
    {
        return __('seo-content-ai::filament.keyword_intelligence.nav');
    }

    public function mount(): void
    {
        $this->mountAiKeywordDiscoveryFilters();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.ai_discovery_title');
    }

    protected function resolveAiDiscoverySiteId(): ?int
    {
        return SeoAccessControl::globalSiteId();
    }
}
