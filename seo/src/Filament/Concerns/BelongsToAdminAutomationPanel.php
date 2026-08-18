<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Filament\Facades\Filament;

/**
 * Automation product UI lives on the SEO panel (/seo/{hash}/...), not /admin.
 * /admin is the local client shell (users, sites, SEO DB connections).
 */
trait BelongsToAdminAutomationPanel
{
    public static function getNavigationGroup(): ?string
    {
        if (Filament::getCurrentPanel()?->getId() !== 'seo') {
            return null;
        }

        return 'Automation';
    }

    public static function getNavigationParentItem(): ?string
    {
        return null;
    }

    /**
     * Shown in the SEO sidebar. Never registered into /admin.
     * Match Filament Page/Resource signature (no params).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'seo';
    }

    public static function adminPanelId(): string
    {
        return 'seo';
    }

    public static function isSeoPanelRequest(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'seo';
    }
}
