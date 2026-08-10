<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordAnalysisOperation;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordProjectConversion;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiTopic;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalLinkSuggestion;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoTopicalMapVersion;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordCannibalizationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordTopicalMapMutationService;
use RuntimeException;

/**
 * Agent/Filament read surface cho Keyword Intelligence — chỉ trả về public refs,
 * không leak numeric ID nội bộ. Mirror ContentProjectAgentReadService.
 */
final class KeywordIntelligenceReadService
{
    public function __construct(
        private readonly KeywordCannibalizationService $cannibalization,
        private readonly KeywordTopicalMapMutationService $mapMutations,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listWorkspaces(int $siteId, array $input = []): array
    {
        $query = SeoKeywordWorkspace::query()->orderByDesc('id')->limit(100);
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->get()
            ->map(fn (SeoKeywordWorkspace $w): array => $this->serializeWorkspace($w))
            ->all();

        return ['workspaces' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWorkspace(int $siteId, string $workspaceRef): array
    {
        return $this->serializeWorkspace($this->resolveWorkspace($siteId, $workspaceRef), true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listKeywords(int $siteId, string $workspaceRef, array $input = []): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);

        $query = SeoKiKeyword::query()->where('workspace_id', $workspace->id)->orderByDesc('priority_score');

        $clusterRef = trim((string) ($input['cluster_ref'] ?? ''));
        if ($clusterRef !== '') {
            $query->where('cluster_id', KeywordIntelligencePublicRef::resolveClusterIdStrict($clusterRef));
        }

        $reviewStatus = trim((string) ($input['review_status'] ?? ''));
        if ($reviewStatus !== '') {
            $query->where('review_status', $reviewStatus);
        }

        $limit = max(1, min(500, (int) ($input['limit'] ?? 100)));

        $rows = $query->limit($limit)->get()
            ->map(fn (SeoKiKeyword $k): array => $this->serializeKeyword($k))
            ->all();

        return ['workspace_ref' => $workspace->public_ref, 'keywords' => $rows];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listClusters(int $siteId, string $workspaceRef, array $input = []): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);

        $query = SeoKeywordCluster::query()->where('workspace_id', $workspace->id)->orderByDesc('priority_score');

        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $limit = max(1, min(500, (int) ($input['limit'] ?? 200)));

        $rows = $query->limit($limit)->get()
            ->map(fn (SeoKeywordCluster $c): array => $this->serializeCluster($c))
            ->all();

        return ['workspace_ref' => $workspace->public_ref, 'clusters' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTopicalMap(int $siteId, string $workspaceRef): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);

        $latest = SeoTopicalMapVersion::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('version')
            ->first();

        if (! $latest instanceof SeoTopicalMapVersion) {
            return ['workspace_ref' => $workspace->public_ref, 'map_version' => null];
        }

        return [
            'workspace_ref' => $workspace->public_ref,
            'map_version' => [
                'map_version_ref' => $latest->public_ref,
                'version' => $latest->version,
                'status' => $latest->status,
                'mode' => $latest->mode,
                'snapshot' => $latest->snapshot,
                'summary' => $latest->summary,
                'generated_at' => $latest->generated_at?->toIso8601String(),
                'approved_at' => $latest->approved_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listTopics(int $siteId, string $workspaceRef, array $input = []): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $limit = max(1, min(500, (int) ($input['limit'] ?? 200)));

        $rows = SeoKiTopic::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('depth')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (SeoKiTopic $t): array => $this->serializeTopic($t))
            ->all();

        return ['workspace_ref' => $workspace->public_ref, 'topics' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTopic(int $siteId, string $workspaceRef, string $topicRef): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $topic = $this->mapMutations->resolveTopic($workspace, $topicRef);

        return ['workspace_ref' => $workspace->public_ref, 'topic' => $this->serializeTopic($topic, true)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listMapConflicts(int $siteId, string $workspaceRef): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);

        return [
            'workspace_ref' => $workspace->public_ref,
            'conflicts' => $this->mapMutations->detectConflicts($workspace),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listLinkSuggestions(int $siteId, string $workspaceRef, array $input = []): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $limit = max(1, min(500, (int) ($input['limit'] ?? 100)));

        $query = SeoTopicalLinkSuggestion::query()->where('workspace_id', $workspace->id)->orderByDesc('priority');
        $mapRef = trim((string) ($input['map_version_ref'] ?? ''));
        if ($mapRef !== '') {
            $query->where('topical_map_version_id', KeywordIntelligencePublicRef::resolveMapVersionIdStrict($mapRef));
        }

        $rows = $query->limit($limit)->get()->map(static function (SeoTopicalLinkSuggestion $s): array {
            return [
                'link_suggestion_ref' => $s->public_ref,
                'relationship' => $s->relationship,
                'status' => $s->status,
                'priority' => $s->priority !== null ? (float) $s->priority : null,
                'confidence' => $s->confidence !== null ? (float) $s->confidence : null,
                'reason_codes' => $s->reason_codes,
                'source_cluster_ref' => $s->source_cluster_id !== null
                    ? KeywordIntelligencePublicRef::cluster((int) $s->source_cluster_id)
                    : null,
                'target_cluster_ref' => $s->target_cluster_id !== null
                    ? KeywordIntelligencePublicRef::cluster((int) $s->target_cluster_id)
                    : null,
                'map_version_ref' => $s->topical_map_version_id !== null
                    ? KeywordIntelligencePublicRef::mapVersion((int) $s->topical_map_version_id)
                    : null,
            ];
        })->all();

        return ['workspace_ref' => $workspace->public_ref, 'link_suggestions' => $rows];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function listMapVersions(int $siteId, string $workspaceRef, array $input = []): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $limit = max(1, min(100, (int) ($input['limit'] ?? 50)));

        $rows = SeoTopicalMapVersion::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('version')
            ->limit($limit)
            ->get()
            ->map(static fn (SeoTopicalMapVersion $v): array => [
                'map_version_ref' => $v->public_ref,
                'version' => $v->version,
                'status' => $v->status,
                'mode' => $v->mode,
                'summary' => $v->summary,
                'generated_at' => $v->generated_at?->toIso8601String(),
                'approved_at' => $v->approved_at?->toIso8601String(),
            ])
            ->all();

        return ['workspace_ref' => $workspace->public_ref, 'map_versions' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function compareMapVersions(
        int $siteId,
        string $workspaceRef,
        string $leftMapVersionRef,
        string $rightMapVersionRef,
    ): array {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $left = $this->mapMutations->resolveMapVersion($workspace, $leftMapVersionRef);
        $right = $this->mapMutations->resolveMapVersion($workspace, $rightMapVersionRef);

        $leftPillars = collect((array) (($left->snapshot['pillars'] ?? []) ?: []))->keyBy('topic_ref');
        $rightPillars = collect((array) (($right->snapshot['pillars'] ?? []) ?: []))->keyBy('topic_ref');

        $added = $rightPillars->keys()->diff($leftPillars->keys())->values()->all();
        $removed = $leftPillars->keys()->diff($rightPillars->keys())->values()->all();
        $shared = $leftPillars->keys()->intersect($rightPillars->keys());
        $changed = [];
        foreach ($shared as $ref) {
            if (($leftPillars[$ref]['cluster_count'] ?? null) !== ($rightPillars[$ref]['cluster_count'] ?? null)
                || ($leftPillars[$ref]['name'] ?? null) !== ($rightPillars[$ref]['name'] ?? null)) {
                $changed[] = $ref;
            }
        }

        return [
            'workspace_ref' => $workspace->public_ref,
            'left' => [
                'map_version_ref' => $left->public_ref,
                'version' => $left->version,
                'status' => $left->status,
                'summary' => $left->summary,
            ],
            'right' => [
                'map_version_ref' => $right->public_ref,
                'version' => $right->version,
                'status' => $right->status,
                'summary' => $right->summary,
            ],
            'diff' => [
                'pillars_added' => $added,
                'pillars_removed' => $removed,
                'pillars_changed' => $changed,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getConversion(int $siteId, string $conversionRef): array
    {
        $id = KeywordIntelligencePublicRef::resolveConversionIdStrict($conversionRef);
        $conversion = SeoKeywordProjectConversion::query()->find($id);

        if (! $conversion instanceof SeoKeywordProjectConversion) {
            throw new RuntimeException('Conversion không tồn tại.');
        }

        if ($siteId > 0 && (int) $conversion->site_id !== $siteId) {
            throw new RuntimeException('Conversion không thuộc site hiện tại.');
        }

        return [
            'conversion' => [
                'conversion_ref' => $conversion->public_ref,
                'workspace_ref' => KeywordIntelligencePublicRef::workspace((int) $conversion->workspace_id),
                'map_version_ref' => KeywordIntelligencePublicRef::mapVersion((int) $conversion->topical_map_version_id),
                'content_project_ref' => $conversion->content_project_ref,
                'status' => $conversion->status,
                'selected_cluster_refs' => $conversion->selected_cluster_refs,
                'summary' => $conversion->summary,
                'created_at' => $conversion->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listCannibalization(int $siteId, string $workspaceRef): array
    {
        $workspace = $this->resolveWorkspace($siteId, $workspaceRef);
        $risks = $this->cannibalization->detect($workspace);

        return ['workspace_ref' => $workspace->public_ref, 'risks' => $risks];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalysisOperation(int $siteId, string $operationRef): array
    {
        $id = KeywordIntelligencePublicRef::resolveOperationIdStrict($operationRef);
        $operation = SeoKeywordAnalysisOperation::query()->find($id);

        if (! $operation instanceof SeoKeywordAnalysisOperation) {
            throw new RuntimeException('Operation không tồn tại.');
        }

        if ($siteId > 0 && (int) $operation->site_id !== $siteId) {
            throw new RuntimeException('Operation không thuộc site hiện tại.');
        }

        return [
            'operation_ref' => $operation->public_ref,
            'workspace_ref' => KeywordIntelligencePublicRef::workspace((int) $operation->workspace_id),
            'status' => $operation->status,
            'stage' => $operation->stage?->value,
            'progress' => $operation->progress,
            'result_code' => $operation->result_code,
            'summary' => $operation->summary,
            'error' => $operation->error,
            'created_at' => $operation->created_at?->toIso8601String(),
        ];
    }

    private function resolveWorkspace(int $siteId, string $workspaceRef): SeoKeywordWorkspace
    {
        $id = KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($workspaceRef);
        $workspace = SeoKeywordWorkspace::query()->find($id);

        if (! $workspace instanceof SeoKeywordWorkspace) {
            throw new RuntimeException('Workspace không tồn tại.');
        }

        if ($siteId > 0 && (int) $workspace->site_id !== $siteId) {
            throw new RuntimeException('Workspace không thuộc site hiện tại.');
        }

        return $workspace;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWorkspace(SeoKeywordWorkspace $workspace, bool $detailed = false): array
    {
        $base = [
            'workspace_ref' => $workspace->public_ref,
            'site_ref' => ContentProjectPublicRef::site((int) $workspace->site_id),
            'name' => $workspace->name,
            'status' => $workspace->status?->value,
            'language' => $workspace->language,
            'country' => $workspace->country,
            'keyword_count' => $workspace->keyword_count,
            'cluster_count' => $workspace->cluster_count,
            'topic_count' => $workspace->topic_count,
            'last_analyzed_at' => $workspace->last_analyzed_at?->toIso8601String(),
            'created_at' => $workspace->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $base['description'] = $workspace->description;
            $base['clustering_strategy'] = $workspace->clustering_strategy;
            $base['summary'] = $workspace->summary;
            $base['archived_at'] = $workspace->archived_at?->toIso8601String();
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeKeyword(SeoKiKeyword $keyword): array
    {
        return [
            'keyword_ref' => $keyword->public_ref,
            'keyword' => $keyword->keyword,
            'search_intent' => $keyword->search_intent?->value,
            'funnel_stage' => $keyword->funnel_stage?->value,
            'search_volume' => $keyword->search_volume,
            'priority_score' => $keyword->priority_score,
            'analysis_status' => $keyword->analysis_status?->value,
            'review_status' => $keyword->review_status?->value,
            'cluster_ref' => $keyword->cluster_id !== null
                ? KeywordIntelligencePublicRef::cluster((int) $keyword->cluster_id)
                : null,
            'is_primary' => (bool) $keyword->is_primary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTopic(SeoKiTopic $topic, bool $detailed = false): array
    {
        $base = [
            'topic_ref' => $topic->public_ref,
            'parent_topic_ref' => $topic->parent_id !== null
                ? KeywordIntelligencePublicRef::topic((int) $topic->parent_id)
                : null,
            'name' => $topic->name,
            'slug' => $topic->slug,
            'topic_type' => $topic->topic_type instanceof \BackedEnum ? $topic->topic_type->value : (string) $topic->topic_type,
            'status' => $topic->status instanceof \BackedEnum ? $topic->status->value : (string) $topic->status,
            'depth' => (int) $topic->depth,
            'keyword_count' => (int) $topic->keyword_count,
            'cluster_count' => (int) $topic->cluster_count,
            'total_search_volume' => (int) $topic->total_search_volume,
        ];

        if ($detailed) {
            $base['path'] = $topic->path;
            $base['metadata'] = $topic->metadata;
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCluster(SeoKeywordCluster $cluster): array
    {
        return [
            'cluster_ref' => $cluster->public_ref,
            'name' => $cluster->name,
            'status' => $cluster->status?->value,
            'cluster_type' => $cluster->cluster_type?->value,
            'search_intent' => $cluster->search_intent?->value,
            'keyword_count' => $cluster->keyword_count,
            'total_search_volume' => $cluster->total_search_volume,
            'priority_score' => $cluster->priority_score,
            'suggested_content_type' => $cluster->suggested_content_type,
            'suggested_title' => $cluster->suggested_title,
            'target_article_ref' => $cluster->target_article_ref,
            'content_project_ref' => $cluster->content_project_ref,
            'topic_ref' => $cluster->topic_id !== null
                ? KeywordIntelligencePublicRef::topic((int) $cluster->topic_id)
                : null,
        ];
    }
}
