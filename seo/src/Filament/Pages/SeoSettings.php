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

    protected static ?int $navigationSort = 11;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-redirect';

    public static function getNavigationUrl(): string
    {
        return SeoSettingsGeneral::getUrl();
    }

    public function mount(): void
    {
        $this->redirect(SeoSettingsGeneral::getUrl(), navigate: false);
    }

    public static function canAccess(): bool
    {
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
