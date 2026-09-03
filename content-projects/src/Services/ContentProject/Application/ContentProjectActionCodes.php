<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application;

/**
 * Mã kết quả chuẩn — Filament/API/Agent đọc code, không parse message.
 */
final class ContentProjectActionCodes
{
    public const PROJECT_CREATED = 'project.created';

    public const PROJECT_UPDATED = 'project.updated';

    public const ITEMS_ADDED = 'items.added';

    public const ITEMS_UPDATED = 'items.updated';

    public const ITEMS_GENERATE_REQUESTED = 'items.generate_requested';

    public const ITEMS_REVIEW_STARTED = 'items.review_started';

    public const ITEMS_APPROVED = 'items.approved';

    public const ITEMS_SCHEDULED = 'items.scheduled';

    public const ITEMS_UNSCHEDULED = 'items.unscheduled';

    public const ITEMS_PUBLISH_QUEUED = 'items.publish_queued';

    public const ITEMS_PUBLISH_RETRIED = 'items.publish_retried';

    public const ITEMS_PUBLISH_SKIPPED = 'items.publish_skipped';

    public const ITEMS_PUBLISH_CANCELLED = 'items.publish_cancelled';

    public const ITEMS_PUBLISH_RECOVERED = 'items.publish_recovered';

    public const ITEMS_PUBLISH_RECONCILED = 'items.publish_reconciled';

    public const ITEMS_SENT_TO_PUBLISHING_QUEUE = 'items.sent_to_publishing_queue';

    public const ITEMS_RETURNED_TO_CONTENT_PROJECT = 'items.returned_to_content_project';

    /** Soft-clear stale generation Failed overlay without re-running AI. */
    public const ITEMS_GENERATION_ERROR_ACKNOWLEDGED = 'items.generation_error_acknowledged';

    /** Manual Existing Article attach for rewrite/improve (no AI start). */
    public const ITEMS_EXISTING_ARTICLE_SELECTED = 'items.existing_article_selected';

    /** Feature-flagged debug/recovery — not WordPress publish. */
    public const ITEMS_DEBUG_LIFECYCLE_OVERRIDDEN = 'items.debug_lifecycle_overridden';

    public const PROJECT_ARCHIVED = 'project.archived';

    public const ITEMS_ARCHIVED = 'items.archived';

    public const PROJECT_RESTORED = 'project.restored';

    public const PREVIEW_READY = 'preview.ready';

    public const IDEMPOTENT_REPLAY = 'idempotent.replay';

    public const PROCESSING = 'processing';

    public const LIFECYCLE_INVALID = 'lifecycle.invalid_transition';

    public const PROJECT_ARCHIVED_BLOCK = 'project.archived';

    /** Draft planning project cannot generate / publish / schedule. */
    public const PROJECT_DRAFT_NOT_EXECUTABLE = 'PROJECT_DRAFT_NOT_EXECUTABLE';

    public const SUGGESTIONS_ADDED = 'suggestions.added';

    public const SUGGESTIONS_NONE_ADDED = 'suggestions.none_added';

    public const IDEA_CANDIDATES_ADDED = 'idea_candidates.added';

    public const SUGGESTIONS_DISMISSED = 'suggestions.dismissed';

    public const SUGGESTIONS_RESTORED = 'suggestions.restored';

    public const SUGGESTIONS_PLANNING_DRAFT_ONLY = 'suggestions.planning_draft_only';

    public const PROJECT_NOT_DRAFT = 'project.not_draft';

    public const DRAFT_SPLIT = 'draft.split';

    public const PRIMARY_LANGUAGE_MISSING = 'primary_language.missing';

    public const NEW_CONTENT_SUGGESTIONS_QUEUED = 'new_content.suggestions_queued';

    /** Global article_meta.skip_seo_audit — not project rejection. */
    public const SEO_AUDIT_ARTICLES_SKIPPED = 'seo_audit.articles_skipped';

    public const ITEMS_NOT_FOUND = 'items.not_found';

    public const PROJECT_NOT_FOUND = 'project.not_found';

    public const FORBIDDEN = 'auth.forbidden';

    public const PUBLISHING_ALREADY_PROCESSING = 'publishing.already_processing';

    public const PUBLISHING_NOT_DUE = 'publishing.not_due';

    public const WORDPRESS_UNAVAILABLE = 'wordpress.connection_unavailable';

    public const LOCK_BUSY = 'concurrency.lock_busy';

    public const OPERATION_LOCKED = 'operation.locked';

    public const OPERATION_ALREADY_PROCESSING = 'operation.already_processing';

    public const CONFIRMATION_REQUIRED = 'confirmation.required';

    public const CONFIRMATION_INVALID = 'confirmation.invalid';

    public const CONFIRMATION_EXPIRED = 'confirmation.expired';

    public const CONFIRMATION_STALE = 'confirmation.stale';

    public const ITEMS_SYNCED = 'items.synced';

    public const PUBLISHED_ARTICLE_WP_SYNCED = 'publishing.published_article_wp_synced';

    public const PUBLISHED_ARTICLE_WP_SYNC_BLOCKED = 'publishing.published_article_wp_sync_blocked';

    public const PUBLISHED_ARTICLE_WP_SYNC_FAILED = 'publishing.published_article_wp_sync_failed';

    public const PUBLISHED_ARTICLES_BULK_RESYNCED = 'publishing.published_articles_bulk_resynced';

    public const EXECUTION_STOPPED = 'execution.stopped';

    public const EXECUTION_RESUMED = 'execution.resumed';

    public const QUOTA_DENIED = 'quota.denied';

    public const VALIDATION_FAILED = 'validation.failed';

    public const FAILED = 'failed';
}
