<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

use App\Models\Site;
use Omnichannel\Addons\SiteSync\Models\SiteHealthState;

final class SiteHealthMonitor
{
    public function __construct(private readonly SiteHealthCheckService $checks, private readonly SiteHealthStateService $states) {}

    public function check(Site $site): SiteHealthState
    {
        return $this->states->record($site, $this->checks->check($site));
    }
}
