<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Contracts;

use App\Models\Site;
use Omnichannel\Addons\SiteSync\Models\SiteHealthIncident;

interface SiteHealthNotificationCapability
{
    public const ID = 'site-health.notifications';

    public function incidentActive(Site $site, SiteHealthIncident $incident): void;

    public function incidentResolved(Site $site, SiteHealthIncident $incident): void;
}
