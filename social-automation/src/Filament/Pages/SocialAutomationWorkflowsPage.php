<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\PackageKindListPage;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;

final class SocialAutomationWorkflowsPage extends Page
{
    use Access;
    use PackageKindListPage;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $slug = 'workflows';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'social-automation::filament.pages.kind-list';

    protected static function packageKind(): string
    {
        return 'workflow';
    }

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.workflows.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.workflows.title');
    }

    public function introKey(): string
    {
        return 'social-automation::filament.workflows.intro';
    }

    public function emptyKey(): string
    {
        return 'social-automation::filament.workflows.empty';
    }
}
