<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Orchestration\JobOrchestrator;

final class SocialAutomationJobsPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $slug = 'jobs';

    protected static ?int $navigationSort = 70;

    protected static string $view = 'social-automation::filament.pages.jobs';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.jobs.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.jobs.title');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return app(JobOrchestrator::class)->listJobsForUi();
    }
}
