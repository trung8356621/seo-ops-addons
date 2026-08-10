<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Filament\Facades\Filament;

/**
 * When Automation UI is hit via legacy /seo/{hash}/… routes, bounce to /admin.
 */
trait RedirectsSeoAutomationToAdmin
{
    protected function redirectSeoAutomationToAdmin(string $adminUrl): bool
    {
        if (Filament::getCurrentPanel()?->getId() !== 'seo') {
            return false;
        }

        $this->redirect($adminUrl, navigate: false);

        return true;
    }
}
