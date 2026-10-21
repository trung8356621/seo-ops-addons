<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;

final class SocialAutomationAccountsPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $slug = 'accounts';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'social-automation::filament.pages.accounts';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.accounts.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.accounts.title');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return app(SessionSyncService::class)->listAccountsForUi();
    }
}
