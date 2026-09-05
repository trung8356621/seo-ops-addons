<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Pages\Page;

class SeoSettings extends Page
{
    protected static ?string $slug = 'settings';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = \Omnichannel\Addons\Seo\Support\SeoUserNavigation::SORT_SETTINGS;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-redirect';

    public static function getNavigationUrl(): string
    {
        try {
            return SeoSettingsGeneral::getUrl(panel: 'admin');
        } catch (\Throwable) {
            return SeoSettingsGeneral::getUrl();
        }
    }

    public function mount(): void
    {
        try {
            $this->redirect(SeoSettingsGeneral::getUrl(panel: 'admin'), navigate: false);
        } catch (\Throwable) {
            $this->redirect(SeoSettingsGeneral::getUrl(), navigate: false);
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user instanceof \App\Models\User
            && in_array((string) $user->role, [\App\Models\User::ROLE_OWNER, \App\Models\User::ROLE_ADMIN], true)
        ) {
            return true;
        }

        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.settings');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.nav.settings');
    }
}
