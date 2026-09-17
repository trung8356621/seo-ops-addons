<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * SEO Audit Notes cluster suggestions — legacy cluster/DNA source retired.
 * Returns empty suggestions so Filament mounts without dropped-table queries.
 */
final class AuditNoteClusterSuggestionQuery
{
    public const PER_PAGE = 25;

    public const DNA_LIMIT = 30;

    /**
     * @param  array{
     *   search?: string,
     *   filter?: string,
     *   page?: int
     * }  $filters
     * @return array{
     *   total: int,
     *   paginator: LengthAwarePaginator,
     *   rows: list<array<string, mixed>>
     * }
     */
    public function paginate(int $siteId, array $filters = [], int $perPage = self::PER_PAGE): array
    {
        unset($siteId, $filters);
        $empty = new Paginator([], 0, $perPage, 1);

        return ['total' => 0, 'paginator' => $empty, 'rows' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSuggestion(int $siteId, string $clusterRef): ?array
    {
        unset($siteId, $clusterRef);

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findExactNormalizedNameMatches(int $siteId, string $normalizedName): array
    {
        unset($siteId, $normalizedName);

        return [];
    }

    /**
     * @return list<string>
     */
    public function dnaPhrasesForCluster(int $siteId, string $clusterRef, int $limit = self::DNA_LIMIT): array
    {
        unset($siteId, $clusterRef, $limit);

        return [];
    }
}
