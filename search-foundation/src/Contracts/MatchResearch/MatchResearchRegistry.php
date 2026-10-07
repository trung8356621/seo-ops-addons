<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch;

use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;

interface MatchResearchRegistry
{
    /**
     * @param  MatchResearchOrigin|null  $origin
     * @return list<MatchResearchResource>
     */
    public function list(?int $siteId = null, ?MatchResearchOrigin $origin = null, ?string $industryContextKey = null): array;

    public function find(string $key, ?int $siteId = null, ?string $industryContextKey = null): ?MatchResearchResource;
}
