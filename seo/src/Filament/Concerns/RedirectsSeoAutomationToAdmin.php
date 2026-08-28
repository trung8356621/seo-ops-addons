<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Filament\Facades\Filament;

/**
 * When Automation pages are still hit via legacy /seo routes, bounce to /admin.
 */
trait RedirectsSeoAutomationToAdmin
{
    protected function redirectSeoAutomationToAdmin(string $adminUrl): bool
    {
        $panelId = Filament::getCurrentPanel()?->getId();

        if ($panelId === 'admin' || $adminUrl === '') {
            return false;
        }

        if (! in_array($panelId, ['seo', 'seo-main'], true)) {
            return false;
        }

        $this->redirect($adminUrl);

        return true;
    }
}
