<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\SocialAutomation\Filament\Pages\Concerns\SocialAutomationPage as Access;
use Omnichannel\Addons\SocialAutomation\Models\SaTask;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class SocialAutomationTasksPage extends Page
{
    use Access;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $slug = 'tasks';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'social-automation::filament.pages.tasks';

    public static function getNavigationLabel(): string
    {
        return __('social-automation::filament.tasks.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('social-automation::filament.tasks.title');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return [];
        }

        return SaTask::query()->orderByDesc('id')->limit(100)->get()->map(static fn (SaTask $t): array => [
            'task_uuid' => $t->task_uuid,
            'name' => $t->name,
            'status' => $t->status,
            'package_key' => $t->package_key,
            'package_version' => $t->package_version,
            'package_kind' => $t->package_kind,
            'target_account_count' => $t->target_account_count,
        ])->all();
    }
}
