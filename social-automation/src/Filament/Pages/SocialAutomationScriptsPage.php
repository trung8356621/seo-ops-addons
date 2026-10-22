<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\PackageKindListPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;

final class SocialAutomationScriptsPage extends Page
{
    use Access;
    use PackageKindListPage;

    protected static ?string $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?string $slug = 'scripts';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'social-automation::filament.pages.kind-list';

    protected static function packageKind(): string
    {
        return 'script';
    }

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.scripts.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.scripts.title');
    }

    public function introKey(): string
    {
        return 'social-automation::filament.scripts.intro';
    }

    public function emptyKey(): string
    {
        return 'social-automation::filament.scripts.empty';
    }
}
