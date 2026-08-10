<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Data;

/**
 * Resolved site/connection scope for actions.
 * Canonical: site_id (App\Models\Site), connection_id (SeoDatabaseConnection).
 */
final class SiteContext
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, bool>  $wordpressCapabilities
     */
    public function __construct(
        public readonly ?int $teamId,
        public readonly ?int $siteId,
        public readonly ?string $siteDomain,
        public readonly ?int $connectionId,
        public readonly ?string $connectionHash,
        public readonly ?string $locale,
        public readonly ?string $timezone,
        public readonly array $wordpressCapabilities = [],
        public readonly array $settings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'team_id' => $this->teamId,
            'site_id' => $this->siteId,
            'site_domain' => $this->siteDomain,
            'connection_id' => $this->connectionId,
            'connection_hash' => $this->connectionHash,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'wordpress_capabilities' => $this->wordpressCapabilities,
            'settings' => $this->settings,
        ];
    }
}
