<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Contracts\IndustryGroup;

use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroup;
use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroupSemanticDefinition;

interface IndustryGroupProvider
{
    /**
     * @return list<IndustryGroup>
     */
    public function list(
        ?int $siteId = null,
        ?string $industryContextKey = null,
        ?string $locale = null,
    ): array;

    public function find(
        string $key,
        ?int $siteId = null,
        ?string $industryContextKey = null,
        ?string $locale = null,
    ): ?IndustryGroup;

    /**
     * @return list<IndustryGroupSemanticDefinition>
     */
    public function semanticDefinitions(
        ?int $siteId = null,
        ?string $industryContextKey = null,
        ?string $locale = null,
    ): array;
}
