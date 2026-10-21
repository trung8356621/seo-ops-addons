<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Scheduling\ScheduleDispatcher;

final class SocialAutomationSchedulesPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $slug = 'schedules';

    protected static ?int $navigationSort = 60;

    protected static string $view = 'social-automation::filament.pages.schedules';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.schedules.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.schedules.title');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return app(ScheduleDispatcher::class)->listSchedulesForUi();
    }
}
