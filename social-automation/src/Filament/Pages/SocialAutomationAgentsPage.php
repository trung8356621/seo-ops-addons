<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService;

final class SocialAutomationAgentsPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $slug = 'agents';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'social-automation::filament.pages.agents';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.agents.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.agents.title');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return app(AgentRegistryService::class)->listForUi();
    }
}
