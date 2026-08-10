<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Illuminate\Http\Client\Response;

final class SeoLinkMapHttpAuditClassifier
{
    public static function classifyResponse(Response $response): SeoLinkMapStatus
    {
        $statusCode = $response->status();

        if ($statusCode === 404) {
            return SeoLinkMapStatus::Broken;
        }

        if ($response->successful() || $response->redirect()) {
            return SeoLinkMapStatus::Active;
        }

        return SeoLinkMapStatus::NeedsAudit;
    }
}
