<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch;

use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;

interface SystemMatchResearchSource
{
    /** @return list<MatchResearchResource> */
    public function resources(): array;

    public function find(string $key): ?MatchResearchResource;
}
