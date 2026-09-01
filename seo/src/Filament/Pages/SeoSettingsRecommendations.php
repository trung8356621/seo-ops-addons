<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages;

use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Pages\Page;

/**
 * Legacy slug — best-practice docs moved to Global Help topics.
 * Redirects to AI Center (model routing / typography settings).
 */
class SeoSettingsRecommendations extends Page
{
    protected static ?string $slug = 'settings/recommendations';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Recommendations';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-recommendations';

    public function mount(): void
    {
        $this->redirect(SeoSettingsAiCenter::getUrl(), navigate: false);
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
