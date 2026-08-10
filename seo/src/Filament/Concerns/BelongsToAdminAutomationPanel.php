<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Filament\Facades\Filament;

/**
 * Automation UI lives on Admin panel (/admin), not SEO panel.
 * Uses Filament navigation group (same pattern as Site Management / SEO on admin).
 */
trait BelongsToAdminAutomationPanel
{
    public static function getNavigationGroup(): ?string
    {
        if (Filament::getCurrentPanel()?->getId() !== 'admin') {
            return null;
        }

        return 'Automation';
    }

    public static function getNavigationParentItem(): ?string
    {
        return null;
    }

    /**
     * Still discovered by SEO panel for legacy URL redirect mounts,
     * but never shown in SEO sidebar.
     * Match Filament Page/Resource signature (no params).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public static function adminPanelId(): string
    {
        return 'admin';
    }

    public static function isSeoPanelRequest(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'seo';
    }
}
