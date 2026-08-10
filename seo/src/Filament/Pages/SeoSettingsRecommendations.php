<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoSettingsRecommendationsContent;
use Filament\Pages\Page;

/**
 * Admin best-practices docs (hard-coded). Does not affect runtime routing.
 */
class SeoSettingsRecommendations extends Page
{
    protected static ?string $slug = 'settings/recommendations';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Recommendations';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-recommendations';

    /**
     * @return array{general_image: string, typography: string, video: string}
     */
    public function currentBadge(): array
    {
        return SeoSettingsRecommendationsContent::currentBadge();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recommendationCards(): array
    {
        return SeoSettingsRecommendationsContent::cards();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
