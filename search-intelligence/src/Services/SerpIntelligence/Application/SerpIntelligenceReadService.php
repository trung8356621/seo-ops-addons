<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application;

use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpContentGap;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpFeature;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpQuery;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpResult;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpSnapshot;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpCollectionOperationService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpCompetitorSummaryService;
use RuntimeException;

final class SerpIntelligenceReadService
{
    public function __construct(
        private readonly SerpCompetitorSummaryService $competitors,
        private readonly SerpCollectionOperationService $operations,
    ) {}

    /** @param array<string, mixed> $input */
    public function listQueries(int $siteId, string $workspaceRef, array $input = []): array
    {
        unset($workspaceRef);
        $this->assertSite($siteId);

        $query = SeoSerpQuery::query()->where('site_id', $siteId)->orderByDesc('id');

        if (trim((string) ($input['status'] ?? '')) !== '') {
            $query->where('status', (string) $input['status']);
        }

        $rows = $query->limit(200)->get()->map(fn (SeoSerpQuery $q): array => $this->serializeQuery($q))->all();

        return ['site_id' => $siteId, 'queries' => $rows];
    }

    public function getQuery(int $siteId, string $workspaceRef, string $queryRef): array
    {
        unset($workspaceRef);
        $query = $this->resolveQuery($siteId, $queryRef);

        return ['query' => $this->serializeQuery($query, true)];
    }

    /** @param array<string, mixed> $input */
    public function listSnapshots(int $siteId, string $workspaceRef, array $input = []): array
    {
        unset($workspaceRef);
        $this->assertSite($siteId);
        $queryRef = trim((string) ($input['query_ref'] ?? ''));

        $snapshotQuery = SeoSerpSnapshot::query()
            ->where('site_id', $siteId)
            ->orderByDesc('captured_at');

        if ($queryRef !== '') {
            $serpQuery = $this->resolveQuery($siteId, $queryRef);
            $snapshotQuery->where('serp_query_id', $serpQuery->id);
        }

        $rows = $snapshotQuery->limit(100)->get()->map(fn (SeoSerpSnapshot $s): array => $this->serializeSnapshot($s))->all();

        return ['site_id' => $siteId, 'snapshots' => $rows];
    }

    public function getSnapshot(int $siteId, string $workspaceRef, string $snapshotRef): array
    {
        unset($workspaceRef);
        $snapshot = $this->resolveSnapshot($snapshotRef);
        if ($siteId > 0 && (int) ($snapshot->site_id ?? 0) !== $siteId) {
            throw new RuntimeException('SERP snapshot not found.');
        }

        return ['snapshot' => $this->serializeSnapshot($snapshot, true)];
    }

    /** @param array<string, mixed> $input */
    public function listResults(int $siteId, string $snapshotRef, array $input = []): array
    {
        unset($siteId, $input);
        $snapshot = $this->resolveSnapshot($snapshotRef);
        $rows = SeoSerpResult::query()
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('position')
            ->limit(100)
            ->get()
            ->map(fn (SeoSerpResult $r): array => [
                'result_ref' => $r->public_ref,
                'position' => $r->position,
                'url' => $r->url,
                'domain' => $r->domain,
                'result_type' => $r->result_type?->value ?? $r->result_type,
            ])
            ->all();

        return ['snapshot_ref' => $snapshot->public_ref, 'results' => $rows];
    }

    /** @param array<string, mixed> $input */
    public function listFeatures(int $siteId, string $snapshotRef, array $input = []): array
    {
        unset($siteId, $input);
        $snapshot = $this->resolveSnapshot($snapshotRef);
        $rows = SeoSerpFeature::query()
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('position')
            ->get()
            ->map(fn (SeoSerpFeature $f): array => [
                'feature_ref' => $f->public_ref,
                'feature_type' => $f->feature_type?->value ?? $f->feature_type,
                'title' => $f->title,
            ])
            ->all();

        return ['snapshot_ref' => $snapshot->public_ref, 'features' => $rows];
    }

    public function getClusterEvidence(int $siteId, string $workspaceRef, string $evidenceRef): array
    {
        unset($siteId, $workspaceRef, $evidenceRef);
        throw new RuntimeException('Cluster evidence retired with Keyword Workspace.');
    }

    /** @param array<string, mixed> $input */
    public function listContentGaps(int $siteId, string $workspaceRef, array $input = []): array
    {
        unset($workspaceRef);
        $this->assertSite($siteId);
        $query = SeoSerpContentGap::query()->where('site_id', $siteId)->orderByDesc('importance_score');

        if (trim((string) ($input['status'] ?? '')) !== '') {
            $query->where('status', (string) $input['status']);
        }

        $rows = $query->limit(200)->get()->map(fn (SeoSerpContentGap $g): array => [
            'gap_ref' => $g->public_ref,
            'gap_type' => $g->gap_type?->value ?? $g->gap_type,
            'status' => $g->status?->value ?? $g->status,
            'importance_score' => $g->importance_score,
        ])->all();

        return ['site_id' => $siteId, 'gaps' => $rows];
    }

    /** @param array<string, mixed> $input */
    public function listCompetitors(int $siteId, string $snapshotRef, array $input = []): array
    {
        unset($siteId, $input);
        $snapshot = $this->resolveSnapshot($snapshotRef);
        $results = SeoSerpResult::query()->where('snapshot_id', $snapshot->id)->orderBy('position')->get()
            ->map(fn (SeoSerpResult $r): array => $r->toArray())->all();

        return [
            'snapshot_ref' => $snapshot->public_ref,
            'competitors' => $this->competitors->summarize($results),
        ];
    }

    public function getOperation(int $siteId, string $operationRef): array
    {
        unset($siteId);
        $operation = $this->operations->getOperation($operationRef);
        if ($operation === null) {
            throw new RuntimeException('Operation not found.');
        }

        return ['operation' => $operation];
    }

    /** @return array<string, mixed> */
    private function serializeQuery(SeoSerpQuery $query, bool $detailed = false): array
    {
        $base = [
            'query_ref' => $query->public_ref,
            'query' => $query->query,
            'status' => $query->status?->value ?? $query->status,
            'provider_key' => $query->provider_key,
            'latest_snapshot_ref' => $query->latest_snapshot_ref,
        ];

        if ($detailed) {
            $base['normalized_query'] = $query->normalized_query;
            $base['language'] = $query->language;
            $base['country'] = $query->country;
            $base['device'] = $query->device?->value ?? $query->device;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function serializeSnapshot(SeoSerpSnapshot $snapshot, bool $detailed = false): array
    {
        $base = [
            'snapshot_ref' => $snapshot->public_ref,
            'status' => $snapshot->status?->value ?? $snapshot->status,
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
            'result_count' => $snapshot->result_count,
            'feature_count' => $snapshot->feature_count,
        ];

        if ($detailed) {
            $base['analysis_summary'] = $snapshot->analysis_summary;
            $base['summary'] = $snapshot->summary;
        }

        return $base;
    }

    private function assertSite(int $siteId): void
    {
        if ($siteId <= 0) {
            throw new RuntimeException('Thiếu site_id.');
        }
    }

    private function resolveQuery(int $siteId, string $queryRef): SeoSerpQuery
    {
        $this->assertSite($siteId);
        $id = KeywordIntelligencePublicRef::resolveSerpQueryIdStrict($queryRef);
        $query = SeoSerpQuery::query()->where('site_id', $siteId)->where('id', $id)->first();

        if (! $query instanceof SeoSerpQuery) {
            throw new RuntimeException('SERP query not found.');
        }

        return $query;
    }

    private function resolveSnapshot(string $snapshotRef): SeoSerpSnapshot
    {
        $id = KeywordIntelligencePublicRef::resolveSerpSnapshotIdStrict($snapshotRef);
        $snapshot = SeoSerpSnapshot::query()->find($id);

        if (! $snapshot instanceof SeoSerpSnapshot) {
            throw new RuntimeException('SERP snapshot not found.');
        }

        return $snapshot;
    }
}
