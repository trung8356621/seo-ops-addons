<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordDnaService;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpClusterTopicalProfileBuilder;

/**
 * SEO Audit Notes cluster suggestions — Cluster SSOT, MCP share ASC (low → high).
 * List path is lightweight (no per-row DNA hydrate). Full DNA loads only on select.
 */
final class AuditNoteClusterSuggestionQuery
{
    public const PER_PAGE = 25;

    public const DNA_LIMIT = 30;

    public function __construct(
        private readonly KeywordClusterQuery $clusters,
        private readonly SiteMcpClusterTopicalProfileBuilder $topicalProfile,
        private readonly KeywordDnaService $dna,
    ) {}

    /**
     * @param  array{
     *   search?: string,
     *   filter?: string,
     *   page?: int
     * }  $filters
     * @return array{
     *   total: int,
     *   paginator: LengthAwarePaginator,
     *   rows: list<array{
     *     cluster_ref: string,
     *     cluster_name: string,
     *     mcp_share: float,
     *     dna_count: int,
     *     article_count: int,
     *     has_focus_article: bool
     *   }>
     * }
     */
    public function paginate(int $siteId, array $filters = [], int $perPage = self::PER_PAGE): array
    {
        if ($siteId <= 0 || ! $this->clusters->classificationsReady()) {
            $empty = new Paginator([], 0, $perPage, 1);

            return ['total' => 0, 'paginator' => $empty, 'rows' => []];
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $filter = trim((string) ($filters['filter'] ?? 'all'));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $items = $this->buildSuggestionItems($siteId);
        $items = $this->applyFilter($items, $filter);
        $items = $this->applySearch($siteId, $items, $search);

        usort(
            $items,
            static function (array $a, array $b): int {
                $byShare = ((float) $a['mcp_share']) <=> ((float) $b['mcp_share']);
                if ($byShare !== 0) {
                    return $byShare;
                }

                return strcmp(
                    mb_strtolower((string) $a['cluster_name'], 'UTF-8'),
                    mb_strtolower((string) $b['cluster_name'], 'UTF-8'),
                );
            },
        );

        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);
        $paginator = new Paginator($slice, $total, $perPage, $page, [
            'path' => '/',
            'pageName' => 'auditNotesPage',
        ]);

        return [
            'total' => $total,
            'paginator' => $paginator,
            'rows' => array_values($slice),
        ];
    }

    /**
     * @return array{
     *   cluster_ref: string,
     *   cluster_name: string,
     *   mcp_share: float,
     *   dna_count: int,
     *   article_count: int,
     *   has_focus_article: bool,
     *   cluster_dna: list<array{phrase: string, weight: int}>
     * }|null
     */
    public function findSuggestion(int $siteId, string $clusterRef): ?array
    {
        $clusterRef = trim($clusterRef);
        if ($siteId <= 0 || $clusterRef === '') {
            return null;
        }

        foreach ($this->buildSuggestionItems($siteId) as $item) {
            if ($item['cluster_ref'] !== $clusterRef) {
                continue;
            }
            $dna = $this->loadClusterDna($siteId, $clusterRef);
            $item['cluster_dna'] = $dna;
            $item['dna_count'] = count($dna);

            return $item;
        }

        return null;
    }

    /**
     * Lightweight list rows — DNA count only (batched), no phrase hydrate.
     *
     * @return list<array{
     *   cluster_ref: string,
     *   cluster_name: string,
     *   mcp_share: float,
     *   dna_count: int,
     *   article_count: int,
     *   has_focus_article: bool
     * }>
     */
    private function buildSuggestionItems(int $siteId): array
    {
        $profile = $this->topicalProfile->build($siteId);
        $topics = is_array($profile['topics'] ?? null) ? $profile['topics'] : [];
        $dnaCounts = $this->dnaCountsByCluster($siteId);
        $out = [];

        foreach ($topics as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $ref = trim((string) ($topic['cluster_ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $name = trim((string) ($topic['name'] ?? ''));
            if ($name === '') {
                $name = $ref;
            }
            $share = (float) ($topic['weight'] ?? 0);
            $articleCount = (int) ($topic['article_count'] ?? 0);
            $dnaCount = (int) ($dnaCounts[$ref] ?? 0);
            if ($dnaCount === 0 && is_array($topic['dna'] ?? null)) {
                $dnaCount = count($topic['dna']);
            }

            $out[] = [
                'cluster_ref' => $ref,
                'cluster_name' => $name,
                'mcp_share' => round($share, 1),
                'dna_count' => $dnaCount,
                'article_count' => $articleCount,
                'has_focus_article' => $articleCount > 0,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function dnaCountsByCluster(int $siteId): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna')) {
            return [];
        }

        $rows = SeoKeywordDna::query()
            ->where('site_id', $siteId)
            ->select('cluster_key', DB::raw('COUNT(DISTINCT normalized_value) as dna_count'))
            ->groupBy('cluster_key')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row->cluster_key ?? ''));
            if ($key === '') {
                continue;
            }
            $out[$key] = (int) ($row->dna_count ?? 0);
        }

        return $out;
    }

    /**
     * @return list<array{phrase: string, weight: int}>
     */
    private function loadClusterDna(int $siteId, string $clusterRef): array
    {
        $branches = array_slice(
            $this->dna->coverageForCluster($siteId, $clusterRef),
            0,
            self::DNA_LIMIT,
        );
        $out = [];
        foreach ($branches as $branch) {
            $phrase = AuditNoteDnaNormalizer::displayPhrase((string) ($branch['value'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $weight = (int) ($branch['count'] ?? AuditNoteDnaNormalizer::DEFAULT_WEIGHT);
            if ($weight < 1) {
                $weight = AuditNoteDnaNormalizer::DEFAULT_WEIGHT;
            }
            $out[] = [
                'phrase' => $phrase,
                'weight' => $weight,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applyFilter(array $items, string $filter): array
    {
        return match ($filter) {
            'mcp_low' => array_values(array_filter(
                $items,
                static fn (array $row): bool => (float) ($row['mcp_share'] ?? 0) < 5.0,
            )),
            'has_focus' => array_values(array_filter(
                $items,
                static fn (array $row): bool => (bool) ($row['has_focus_article'] ?? false),
            )),
            'no_focus' => array_values(array_filter(
                $items,
                static fn (array $row): bool => ! (bool) ($row['has_focus_article'] ?? false),
            )),
            default => $items,
        };
    }

    /**
     * Search cluster_name + DNA phrase → parent Cluster.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function applySearch(int $siteId, array $items, string $search): array
    {
        $needle = mb_strtolower(trim($search), 'UTF-8');
        if ($needle === '') {
            return $items;
        }

        $dnaMatchedRefs = $this->clusterRefsMatchingDna($siteId, $needle);

        return array_values(array_filter(
            $items,
            static function (array $row) use ($needle, $dnaMatchedRefs): bool {
                $ref = (string) ($row['cluster_ref'] ?? '');
                if ($ref !== '' && isset($dnaMatchedRefs[$ref])) {
                    return true;
                }
                $name = mb_strtolower((string) ($row['cluster_name'] ?? ''), 'UTF-8');

                return $name !== '' && str_contains($name, $needle);
            },
        ));
    }

    /**
     * @return array<string, true>
     */
    private function clusterRefsMatchingDna(int $siteId, string $needle): array
    {
        if ($needle === '' || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna')) {
            return [];
        }

        $like = '%'.$needle.'%';
        $keys = SeoKeywordDna::query()
            ->where('site_id', $siteId)
            ->where(static function ($q) use ($like): void {
                $q->where('value', 'like', $like)
                    ->orWhere('normalized_value', 'like', $like);
            })
            ->distinct()
            ->limit(500)
            ->pluck('cluster_key');

        $out = [];
        foreach ($keys as $key) {
            $ref = trim((string) $key);
            if ($ref !== '') {
                $out[$ref] = true;
            }
        }

        return $out;
    }
}
