<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Filament\Facades\Filament;

/**
 * Automation product UI lives on /admin/automation/*.
 * Legacy /seo/.../automation/* mounts redirect here.
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
     * Shown only in the /admin sidebar.
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
        $panelId = Filament::getCurrentPanel()?->getId();

        return in_array($panelId, ['seo', 'seo-main'], true);
    }
}
