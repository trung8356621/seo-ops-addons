<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\GscContext;

use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;

/**
 * Request/job-scoped GSC load memoization so multiple GSC slices share one load.
 *
 * Registered via container `scoped()` — cache must not survive across requests/jobs.
 */
final class GscContextSource
{
    /** @var array<string, GscContext> */
    private array $cache = [];

    public function __construct(
        private readonly GscContextGateway $gateway,
    ) {}

    public function load(int $siteId, string $periodKey): GscContext
    {
        $key = $siteId.'|'.$periodKey;
        if (! isset($this->cache[$key])) {
            $this->cache[$key] = $this->gateway->forSite($siteId, $periodKey);
        }

        return $this->cache[$key];
    }

    public function sourceUpdatedAt(int $siteId): ?string
    {
        return $this->gateway->sourceUpdatedAt($siteId);
    }
}
