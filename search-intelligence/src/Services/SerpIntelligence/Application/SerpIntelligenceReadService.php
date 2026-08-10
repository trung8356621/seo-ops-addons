<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpClusterEvidence;
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
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $query = SeoSerpQuery::query()->where('workspace_id', $workspace->id)->orderByDesc('id');

        if (trim((string) ($input['status'] ?? '')) !== '') {
            $query->where('status', (string) $input['status']);
        }

        $rows = $query->limit(200)->get()->map(fn (SeoSerpQuery $q): array => $this->serializeQuery($q))->all();

        return ['workspace_ref' => $workspace->public_ref, 'queries' => $rows];
    }

    public function getQuery(int $siteId, string $workspaceRef, string $queryRef): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $query = $this->resolveQuery($workspace, $queryRef);

        return ['query' => $this->serializeQuery($query, true)];
    }

    /** @param array<string, mixed> $input */
    public function listSnapshots(int $siteId, string $workspaceRef, array $input = []): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $queryRef = trim((string) ($input['query_ref'] ?? ''));

        $snapshotQuery = SeoSerpSnapshot::query()
            ->where('site_id', $workspace->site_id)
            ->orderByDesc('captured_at');

        if ($queryRef !== '') {
            $serpQuery = $this->resolveQuery($workspace, $queryRef);
            $snapshotQuery->where('serp_query_id', $serpQuery->id);
        }

        $rows = $snapshotQuery->limit(100)->get()->map(fn (SeoSerpSnapshot $s): array => $this->serializeSnapshot($s))->all();

        return ['workspace_ref' => $workspace->public_ref, 'snapshots' => $rows];
    }

    public function getSnapshot(int $siteId, string $workspaceRef, string $snapshotRef): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $snapshot = $this->resolveSnapshot($snapshotRef);

        return ['snapshot' => $this->serializeSnapshot($snapshot, true)];
    }

    /** @param array<string, mixed> $input */
    public function listResults(int $siteId, string $snapshotRef, array $input = []): array
    {
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
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $id = KeywordIntelligencePublicRef::resolveSerpClusterEvidenceIdStrict($evidenceRef);
        $evidence = SeoSerpClusterEvidence::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $id)
            ->first();

        if (! $evidence instanceof SeoSerpClusterEvidence) {
            throw new RuntimeException('Cluster evidence not found.');
        }

        return ['evidence' => $this->serializeEvidence($evidence)];
    }

    /** @param array<string, mixed> $input */
    public function listContentGaps(int $siteId, string $workspaceRef, array $input = []): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $query = SeoSerpContentGap::query()->where('workspace_id', $workspace->id)->orderByDesc('importance_score');

        if (trim((string) ($input['status'] ?? '')) !== '') {
            $query->where('status', (string) $input['status']);
        }

        $rows = $query->limit(200)->get()->map(fn (SeoSerpContentGap $g): array => [
            'gap_ref' => $g->public_ref,
            'gap_type' => $g->gap_type?->value ?? $g->gap_type,
            'status' => $g->status?->value ?? $g->status,
            'importance_score' => $g->importance_score,
        ])->all();

        return ['workspace_ref' => $workspace->public_ref, 'gaps' => $rows];
    }

    /** @param array<string, mixed> $input */
    public function listCompetitors(int $siteId, string $snapshotRef, array $input = []): array
    {
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

    /** @return array<string, mixed> */
    private function serializeEvidence(SeoSerpClusterEvidence $evidence): array
    {
        return [
            'evidence_ref' => $evidence->public_ref,
            'cluster_ref' => KeywordIntelligencePublicRef::cluster((int) $evidence->cluster_id),
            'status' => $evidence->status?->value ?? $evidence->status,
            'observed_intent' => $evidence->observed_intent,
            'dominant_page_type' => $evidence->dominant_page_type?->value ?? $evidence->dominant_page_type,
            'recommended_action' => $evidence->recommended_action,
        ];
    }

    private function resolveWorkspace(int $siteId, string $workspaceRef): SeoKeywordWorkspace
    {
        $id = KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($workspaceRef);
        $workspace = SeoKeywordWorkspace::query()->find($id);

        if (! $workspace instanceof SeoKeywordWorkspace) {
            throw new RuntimeException('Workspace not found.');
        }

        if ($siteId > 0 && (int) $workspace->site_id !== $siteId) {
            throw new RuntimeException('Workspace does not belong to site.');
        }

        return $workspace;
    }

    private function resolveQuery(SeoKeywordWorkspace $workspace, string $queryRef): SeoSerpQuery
    {
        $id = KeywordIntelligencePublicRef::resolveSerpQueryIdStrict($queryRef);
        $query = SeoSerpQuery::query()->where('workspace_id', $workspace->id)->where('id', $id)->first();

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
