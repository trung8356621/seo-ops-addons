<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

/**
 * Legacy no-op. Automation product UI stays on /seo.
 * /admin no longer hosts Automation pages/resources.
 */
trait RedirectsSeoAutomationToAdmin
{
    protected function redirectSeoAutomationToAdmin(string $adminUrl): bool
    {
        return false;
    }
}
