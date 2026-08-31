<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Orchestration;

use App\Models\Site;
use Omnichannel\Addons\SiteSync\Services\Capability\SiteCapabilityResolver;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;

/**
 * Chooses V3 vs V2 at sync entry — capability hit, else discover probe, else V2.
 */
final class SiteSyncProtocolRouter
{
    public function __construct(
        private readonly SiteSyncFeatureFlags $flags,
        private readonly SiteCapabilityResolver $capabilities,
        private readonly WordPressSiteSyncV3Client $v3Client,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, message: string, run_id?: int, public_ref?: string, protocol?: int}
     */
    public function start(Site $site, array $options = []): array
    {
        if ($this->shouldUseV3($site)) {
            return app(RunSiteSyncV3Orchestrator::class)->start($site, $options);
        }

        return app(RunSiteSyncOrchestrator::class)->start($site, $options);
    }

    public function shouldUseV3(Site $site): bool
    {
        if (! $this->flags->protocolV3Enabled()) {
            return false;
        }

        if ($this->capabilities->isAvailable($site, SiteSyncV3Schema::CAPABILITY)) {
            return true;
        }

        $probe = $this->v3Client->discover($site);

        return (bool) ($probe['success'] ?? false);
    }
}
