<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

/**
 * Input for TopicalMapBuilder::buildFromRequest().
 * mode is string: conservative|balanced|expansive.
 */
final class TopicalMapBuildRequest
{
    public const MODE_CONSERVATIVE = 'conservative';

    public const MODE_BALANCED = 'balanced';

    public const MODE_EXPANSIVE = 'expansive';

    /**
     * @param  list<string>|null  $approvedClusterRefs public refs (kwc_*) — null = không lọc,
     *   dùng toàn bộ cluster đủ điều kiện theo status/exclusion mặc định
     */
    public function __construct(
        public readonly string $workspaceRef,
        public readonly string $mode = self::MODE_BALANCED,
        public readonly int $workspaceId = 0,
        public readonly ?int $maxDepth = null,
        public readonly bool $preserveManualTopics = true,
        public readonly bool $includeReviewedClusters = false,
        public readonly bool $rebuildDraftAssignments = false,
        public readonly ?array $approvedClusterRefs = null,
        public readonly ?int $actorId = null,
    ) {}

    public static function forWorkspace(int $workspaceId, string $workspaceRef, string $mode = self::MODE_BALANCED): self
    {
        return new self(workspaceRef: $workspaceRef, mode: $mode, workspaceId: $workspaceId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'workspace_ref' => $this->workspaceRef,
            'mode' => $this->mode,
            'max_depth' => $this->maxDepth,
            'preserve_manual_topics' => $this->preserveManualTopics,
            'include_reviewed_clusters' => $this->includeReviewedClusters,
            'rebuild_draft_assignments' => $this->rebuildDraftAssignments,
            'approved_cluster_refs' => $this->approvedClusterRefs,
            'actor_id' => $this->actorId,
        ];
    }
}
