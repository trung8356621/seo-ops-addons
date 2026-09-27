<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Filament\Pages;

use Filament\Pages\Page;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Shell bootstrap only. The workspace UI is the React app.
 */
class AgentRuntimePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $slug = 'agent-runtime';

    protected static ?int $navigationSort = 12;

    protected static string $view = 'agent-runtime::agent-runtime';

    public static function getNavigationLabel(): string
    {
        return 'Agent';
    }

    public function getTitle(): string
    {
        return 'Agent';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessSeoPanel();
    }
}
