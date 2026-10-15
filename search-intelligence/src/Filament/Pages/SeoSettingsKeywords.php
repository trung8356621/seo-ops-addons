<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Filament\Pages;

use App\Core\Addon\AddonEnablement;
use Filament\Pages\Page;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Retired Match & Research settings screen.
 * The Filament route remains so old links redirect to Semantic Routing.
 */
class SeoSettingsKeywords extends Page
{
    protected static ?string $slug = 'settings/keywords';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-keywords';

    public function mount(): void
    {
        $this->redirect('/admin/settings/semantic-routing');
    }

    public function getTitle(): string
    {
        return 'Semantic Routing';
    }

    public static function getNavigationLabel(): string
    {
        return 'Semantic Routing';
    }

    public static function canAccess(): bool
    {
        if (! AddonEnablement::seoStackEnabled()) {
            return false;
        }

        $user = auth()->user();
        if ($user instanceof \App\Models\User
            && in_array((string) $user->role, [\App\Models\User::ROLE_OWNER, \App\Models\User::ROLE_ADMIN], true)
        ) {
            return true;
        }

        return SeoAccessControl::canAccessManagerFeatures();
    }
}
