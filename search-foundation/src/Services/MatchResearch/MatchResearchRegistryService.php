<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchResearch;

use Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch\MatchResearchRegistry;
use Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch\SystemMatchResearchSource;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;

final class MatchResearchRegistryService implements MatchResearchRegistry
{
    public function __construct(
        private readonly ?SystemMatchResearchSource $systemSource = null,
        private readonly ?IndustryMatchResearchProjector $industryProjector = null,
        private readonly ?CustomMatchResearchStore $customStore = null,
        private readonly ?MatchResearchLocaleOverlayStore $localeOverlays = null,
    ) {}

    public function list(?int $siteId = null, ?MatchResearchOrigin $origin = null, ?string $industryContextKey = null): array
    {
        $resources = [];

        if ($origin === null || $origin === MatchResearchOrigin::System) {
            foreach ($this->systemSource?->resources() ?? [] as $resource) {
                $resources[] = $this->withOverlays($resource, $siteId);
            }
        }

        if ($origin === null || $origin === MatchResearchOrigin::Industry) {
            foreach ($this->industryProjector?->project($industryContextKey) ?? [] as $resource) {
                $resources[] = $this->withOverlays($resource, $siteId);
            }
        }

        if ($origin === null || $origin === MatchResearchOrigin::Custom) {
            if ($siteId !== null && $siteId > 0) {
                foreach ($this->customStore?->listForSite($siteId) ?? [] as $resource) {
                    $resources[] = $this->withOverlays($resource, $siteId);
                }
            }
        }

        return $resources;
    }

    public function find(string $key, ?int $siteId = null, ?string $industryContextKey = null): ?MatchResearchResource
    {
        if (str_starts_with($key, 'system.')) {
            $legacy = substr($key, strlen('system.'));
            $resource = $this->systemSource?->find($key) ?? $this->systemSource?->find($legacy);
            return $resource ? $this->withOverlays($resource, $siteId) : null;
        }

        if (str_starts_with($key, 'industry.')) {
            foreach ($this->industryProjector?->project($industryContextKey) ?? [] as $resource) {
                if ($resource->key === $key) {
                    return $this->withOverlays($resource, $siteId);
                }
            }

            return null;
        }

        if (str_starts_with($key, 'custom.') && $siteId !== null) {
            $resource = $this->customStore?->find($key, $siteId);

            return $resource ? $this->withOverlays($resource, $siteId) : null;
        }

        // Legacy system key without prefix
        $resource = $this->systemSource?->find($key);
        if ($resource !== null) {
            return $this->withOverlays($resource, $siteId);
        }

        return null;
    }

    private function withOverlays(MatchResearchResource $resource, ?int $siteId): MatchResearchResource
    {
        if ($this->localeOverlays === null) {
            return $resource;
        }

        $overlays = $this->localeOverlays->overlaysFor($resource->key, $siteId);
        // Never let an overlay replace the source locale identity payload
        unset($overlays[$resource->sourceLocale]);

        if ($overlays === []) {
            return $resource;
        }

        return new MatchResearchResource(
            key: $resource->key,
            origin: $resource->origin,
            kind: $resource->kind,
            sourceLocale: $resource->sourceLocale,
            label: $resource->label,
            description: $resource->description,
            matchMode: $resource->matchMode,
            payload: $resource->payload,
            capabilities: $resource->capabilities,
            editable: $resource->editable,
            deletable: $resource->deletable,
            provenance: $resource->provenance,
            locales: $overlays,
            siteId: $resource->siteId,
            enabled: $resource->enabled,
        );
    }
}
