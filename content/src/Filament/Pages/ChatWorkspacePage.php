<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Filament\Pages;

use Omnichannel\Addons\Seo\Filament\Pages\SeoPanelPage;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Support\Enums\MaxWidth;

/**
 * Chat Workspace shell — Group Chat + Support Ticket.
 * Agent tab is intentionally unavailable (legacy Agent Workspace is reference-only).
 */
final class ChatWorkspacePage extends SeoPanelPage
{
    protected static ?string $slug = 'chat';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.chat-workspace';

    /** @var 'agent'|'group'|'ticket' */
    public string $chatTab = 'group';

    public static function getNavigationLabel(): string
    {
        return 'Chat';
    }

    public function getTitle(): string
    {
        return 'Chat';
    }

    public function getHeading(): string
    {
        return 'Chat';
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessSeoPanel();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canAccessSeoPanel(), 403);

        $tab = strtolower(trim((string) request()->query('tab', 'group')));
        if (! in_array($tab, ['agent', 'group', 'ticket'], true)) {
            $tab = 'group';
        }

        // Agent Workspace runtime is isolated — never land on a live agent UI.
        if ($tab === 'agent') {
            $tab = 'group';
        }

        $this->chatTab = $tab;
    }
}
