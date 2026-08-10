<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\HasKeywordWorkspaceNavigation;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

final class KeywordWorkspaceFour extends Page
{
    use HasKeywordWorkspaceNavigation;

    protected static string $resource = KeywordResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.keywords.pages.keyword-workspace-placeholder';

    protected static bool $shouldRegisterNavigation = false;

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
        return __('seo-content-ai::filament.keyword.workspace_four_title');
    }

    protected function getActiveKeywordWorkspaceKey(): string
    {
        return 'workspace-4';
    }

    public function getPlaceholderDescription(): string
    {
        return __('seo-content-ai::filament.keyword.workspace_four_description');
    }
}
