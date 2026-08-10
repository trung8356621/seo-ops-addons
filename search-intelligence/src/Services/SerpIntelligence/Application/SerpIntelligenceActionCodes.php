<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application;

/**
 * Mã kết quả chuẩn cho SERP Intelligence — Filament/API/Agent đọc code, không parse message.
 */
final class SerpIntelligenceActionCodes
{
    public const QUERIES_CREATED = 'serp.queries_created';

    public const QUERY_UPDATED = 'serp.query_updated';

    public const QUERIES_ARCHIVED = 'serp.queries_archived';

    public const COLLECTION_STARTED = 'serp.collection_started';

    public const COLLECTION_CANCELLED = 'serp.collection_cancelled';

    public const COLLECTION_PARTIAL = 'serp.collection_partial';

    public const SNAPSHOT_IMPORTED = 'serp.snapshot_imported';

    public const SNAPSHOT_ALREADY_IMPORTED = 'serp.snapshot_already_imported';

    public const SNAPSHOT_ANALYZED = 'serp.snapshot_analyzed';

    public const PAGE_EVIDENCE_FETCHED = 'serp.page_evidence_fetched';

    public const PAGE_EVIDENCE_REANALYZED = 'serp.page_evidence_reanalyzed';

    public const CLUSTER_VALIDATED = 'serp.cluster_validated';

    public const WORKSPACE_CLUSTERS_VALIDATED = 'serp.workspace_clusters_validated';

    public const EVIDENCE_APPROVED = 'serp.evidence_approved';

    public const EVIDENCE_REJECTED = 'serp.evidence_rejected';

    public const INTENT_APPLIED = 'serp.intent_applied';

    public const PAGE_TYPE_APPLIED = 'serp.page_type_applied';

    public const CONTENT_ACTION_APPLIED = 'serp.content_action_applied';

    public const GAP_REVIEWED = 'serp.gap_reviewed';

    public const GAP_ACCEPTED = 'serp.gap_accepted';

    public const GAP_IGNORED = 'serp.gap_ignored';

    public const GAP_RESOLVED = 'serp.gap_resolved';

    public const SPLIT_PREVIEW = 'serp.split_preview';

    public const FEATURE_KEYWORDS_ADDED = 'serp.feature_keywords_added';

    public const PREVIEW_READY = 'serp.preview_ready';

    public const NOT_FOUND = 'serp.not_found';

    public const VALIDATION_FAILED = 'serp.validation_failed';

    public const FORBIDDEN = 'serp.forbidden';

    public const CONFIRMATION_REQUIRED = 'serp.confirmation_required';

    public const WORKSPACE_ARCHIVED = 'serp.workspace_archived';

    public const COLLECTION_LOCKED = 'serp.collection_locked';

    public const FAILED = 'serp.failed';
}
