<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Omnichannel\Addons\SearchFoundation\Services\KeywordCannibalizationService;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

final class KeywordCannibalizationWorkspace extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-cannibalization-workspace';

    protected static bool $shouldRegisterNavigation = false;

    private KeywordCannibalizationService $cannibalizationService;

    public function boot(KeywordCannibalizationService $cannibalizationService): void
    {
        $this->cannibalizationService = $cannibalizationService;
    }

    public function mount(): void
    {
        $this->initializeKeywordWorkspaceSiteFilter();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return KeywordResource::canViewAny();
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.keyword.cannibalization_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'cannibalization';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCannibalizationRowsProperty(): array
    {
        return $this->cannibalizationService->detect($this->resolveKeywordWorkspaceSiteId());
    }
}
