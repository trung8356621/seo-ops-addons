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

    /**
     * Why active Match rules are or are not available.
     * One of: active, no_match_revision, match_revision_inactive, match_revision_stale, no_taxonomy_groups.
     * Inactive revisions are never treated as active.
     */
    public function statusForKey(?string $industryContextKey): string;
}
