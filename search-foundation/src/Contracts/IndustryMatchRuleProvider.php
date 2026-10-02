<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Contracts;

interface IndustryMatchRuleProvider
{
    /** @return array<string, list<array<string, mixed>>> */
    public function rulesForSite(int $siteId): array;

    /** @return array<string, list<array<string, mixed>>> */
    public function rulesForKey(?string $industryContextKey): array;

    /** @return array<string, mixed>|null */
    public function provenanceForKey(?string $industryContextKey): ?array;
}
