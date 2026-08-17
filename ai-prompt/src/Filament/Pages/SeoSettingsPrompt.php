<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Pages;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Pages\Page;

class SeoSettingsPrompt extends Page
{
    protected static ?string $slug = 'settings/prompt';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Prompt settings';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-redirect';

    public function mount(): void
    {
        $this->redirect(\Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource::getUrl(), navigate: true);
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
