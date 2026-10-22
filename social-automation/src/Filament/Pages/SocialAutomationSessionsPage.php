<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;

final class SocialAutomationSessionsPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $slug = 'sessions';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'social-automation::filament.pages.sessions';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.sessions.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.sessions.title');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return app(SessionSyncService::class)->listForUi();
    }
}
