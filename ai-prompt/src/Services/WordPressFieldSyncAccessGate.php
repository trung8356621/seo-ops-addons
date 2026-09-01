<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Services\Contracts\WordPressFieldSyncAccessChecker;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;

final class WordPressFieldSyncAccessGate implements WordPressFieldSyncAccessChecker
{
    public function canSync(Site $site): bool
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            return false;
        }

        if (! SeoAccessControl::canAccessSite((int) $site->id)) {
            return false;
        }

        $site->loadMissing('metas');

        return trim((string) ($site->getMeta('seo_platform') ?? '')) === 'wordpress';
    }
}
