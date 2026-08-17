<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Pages;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Pages\Page;

class SeoSettingsAiRouting extends Page
{
    protected static ?string $slug = 'settings/ai-routing';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'AI Routing';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-redirect';

    public function mount(): void
    {
        $this->redirect(SeoSettingsAiCenter::getUrl().'?tab=routing', navigate: true);
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
