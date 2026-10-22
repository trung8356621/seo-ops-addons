<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\PackageKindListPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;

final class SocialAutomationWidgetsPage extends Page
{
    use Access;
    use PackageKindListPage;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $slug = 'widgets';

    protected static ?int $navigationSort = 11;

    protected static string $view = 'social-automation::filament.pages.kind-list';

    protected static function packageKind(): string
    {
        return 'widget';
    }

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.widgets.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.widgets.title');
    }

    public function introKey(): string
    {
        return 'social-automation::filament.widgets.intro';
    }

    public function emptyKey(): string
    {
        return 'social-automation::filament.widgets.empty';
    }
}
