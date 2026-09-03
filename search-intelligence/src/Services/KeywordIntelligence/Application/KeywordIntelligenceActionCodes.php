<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application;

/**
 * Mã kết quả chuẩn cho Keyword Intelligence — Filament/API/Agent đọc code, không parse message.
 */
final class KeywordIntelligenceActionCodes
{
    public const WORKSPACE_CREATED = 'keyword.workspace_created';

    public const IMPORTED = 'keyword.imported';

    public const ANALYZED = 'keyword.analyzed';

    public const CLUSTER_CREATED = 'keyword.cluster_created';

    public const TOPICAL_MAP_BUILT = 'keyword.topical_map_built';

    public const CONTENT_PROJECT_CREATED = 'keyword.content_project_created';

    public const CLUSTERS_APPROVED = 'keyword.clusters_approved';

    public const CLUSTERS_EXCLUDED = 'keyword.clusters_excluded';

    public const KEYWORDS_REVIEWED = 'keyword.keywords_reviewed';

    public const PREVIEW_GENERATED = 'keyword.preview_generated';

    public const KEYWORDS_APPROVED = 'keyword.keywords_approved';

    public const WORKSPACE_ARCHIVED_OK = 'keyword.workspace_archived_ok';

    public const PREVIEW_READY = 'keyword.preview_ready';

    public const WORKSPACE_LIMIT_EXCEEDED = 'keyword.workspace_limit_exceeded';

    public const IMPORT_TOO_LARGE = 'keyword.import_too_large';

    public const ANALYSIS_QUOTA_EXCEEDED = 'keyword.analysis_quota_exceeded';

    public const CONVERSION_TOO_LARGE = 'keyword.conversion_too_large';

    public const WORKSPACE_ARCHIVED = 'keyword.workspace_archived';

    public const NOT_FOUND = 'keyword.not_found';

    public const VALIDATION_FAILED = 'keyword.validation_failed';

    public const FORBIDDEN = 'keyword.forbidden';

    public const CONFIRMATION_REQUIRED = 'keyword.confirmation_required';

    public const FAILED = 'keyword.failed';

    // Phase 2 — analysis pipeline / near-duplicate / cluster review.
    public const ANALYSIS_ALREADY_PROCESSING = 'keyword.analysis_already_processing';

    public const ANALYSIS_CANCELLED = 'keyword.analysis_cancelled';

    public const ANALYSIS_PARTIAL = 'keyword.analysis_partial';

    public const ANALYSIS_TOO_LARGE = 'keyword.analysis_too_large';

    public const AI_QUOTA_EXCEEDED = 'keyword.ai_quota_exceeded';

    public const BUCKET_TRUNCATED = 'keyword.analysis_bucket_truncated';

    public const MAPPING_NEEDS_REVIEW = 'keyword.mapping_needs_review';

    public const CLUSTER_CHANGE_SUGGESTED = 'keyword.cluster_change_suggested';

    public const MERGE_PREVIEW = 'keyword.merge_preview';

    public const SPLIT_PREVIEW = 'keyword.split_preview';

    public const TOPICAL_MAP_BUILD_STARTED = 'topical_map.build_started';

    public const TOPICAL_MAP_BUILD_COMPLETED = 'topical_map.build_completed';

    public const TOPICAL_MAP_BUILD_PARTIAL = 'topical_map.build_partially_completed';

    public const TOPICAL_MAP_BUILD_FAILED = 'topical_map.build_failed';

    public const TOPICAL_MAP_BUILD_CANCELLED = 'topical_map.build_cancelled';

    public const TOPICAL_MAP_NO_APPROVED_CLUSTERS = 'topical_map.no_approved_clusters';

    public const TOPICAL_MAP_HIERARCHY_INVALID = 'topical_map.hierarchy_invalid';

    public const TOPICAL_MAP_ALREADY_BUILDING = 'topical_map.already_building';

    public const TOPICAL_MAP_ANALYSIS_RUNNING = 'topical_map.keyword_analysis_running';

    public const TOPICAL_MAP_APPROVED = 'topical_map.approved';

    public const TOPICAL_MAP_REVIEWED = 'topical_map.reviewed';

    public const TOPICAL_MAP_VERSION_SAVED = 'topical_map.version_saved';

    public const TOPICAL_MAP_APPROVAL_BLOCKED = 'topical_map.approval_blocked';

    public const TOPICAL_MAP_CHANGE_SUGGESTED = 'topical_map.change_suggested';

    public const TOPIC_CREATED = 'topical_map.topic_created';

    public const TOPIC_UPDATED = 'topical_map.topic_updated';

    public const TOPIC_MOVED = 'topical_map.topic_moved';

    public const TOPIC_DELETED = 'topical_map.topic_deleted';

    public const CLUSTER_ATTACHED = 'topical_map.cluster_attached';

    public const CLUSTER_DETACHED = 'topical_map.cluster_detached';

    public const CLUSTER_PRIMARY_MOVED = 'topical_map.cluster_primary_moved';

    public const TOPIC_RELATIONSHIP_SET = 'topical_map.topic_relationship_set';

    public const CONVERSION_IMPROVE_DESCRIPTION_REQUIRED = 'keyword.conversion.improve_description_required';

    public const CONVERSION_ALREADY_CONVERTED = 'keyword.conversion.already_converted';

    public const CONVERSION_PREVIEWED = 'keyword.conversion.previewed';

    public const CONVERSION_COMPLETED = 'keyword.conversion.completed';

    public const CONVERSION_FAILED = 'keyword.conversion.failed';

    public const CONVERSION_MAP_NOT_APPROVED = 'keyword.conversion.map_not_approved';
}
