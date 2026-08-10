<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application;

/**
 * Mã kết quả chuẩn cho GSC Intelligence — Filament/API/Agent đọc code, không parse message.
 */
final class GscIntelligenceActionCodes
{
    public const PROPERTY_CREATED = 'gsc.property_created';

    public const PROPERTY_UPDATED = 'gsc.property_updated';

    public const PROPERTY_PAUSED = 'gsc.property_paused';

    public const PROPERTY_RESUMED = 'gsc.property_resumed';

    public const PROPERTY_ARCHIVED = 'gsc.property_archived';

    public const SYNC_STARTED = 'gsc.sync_started';

    public const SYNC_PARTIAL = 'gsc.sync_partial';

    public const SYNC_CANCELLED = 'gsc.sync_cancelled';

    public const IMPORT_PREVIEW = 'gsc.import_preview';

    public const IMPORT_COMPLETED = 'gsc.import_completed';

    public const DATE_RANGE_REPAIRED = 'gsc.date_range_repaired';

    public const QUERY_MAPPED = 'gsc.query_mapped';

    public const QUERY_UNMAPPED = 'gsc.query_unmapped';

    public const PAGE_MAPPED = 'gsc.page_mapped';

    public const PAGE_UNMAPPED = 'gsc.page_unmapped';

    public const AGGREGATES_REBUILT = 'gsc.aggregates_rebuilt';

    public const OPPORTUNITIES_DETECTED = 'gsc.opportunities_detected';

    public const OPPORTUNITY_APPROVED = 'gsc.opportunity_approved';

    public const OPPORTUNITY_REJECTED = 'gsc.opportunity_rejected';

    public const OPPORTUNITY_IGNORED = 'gsc.opportunity_ignored';

    public const OPPORTUNITY_RESOLVED = 'gsc.opportunity_resolved';

    public const QUERIES_PREVIEW = 'gsc.queries_preview';

    public const QUERIES_ADDED = 'gsc.queries_added';

    public const CONVERSION_PREVIEW = 'gsc.conversion_preview';

    public const CONVERSION_CREATED = 'gsc.conversion_created';

    public const PREVIEW_READY = 'gsc.preview_ready';

    public const NOT_FOUND = 'gsc.not_found';

    public const VALIDATION_FAILED = 'gsc.validation_failed';

    public const FORBIDDEN = 'gsc.forbidden';

    public const CONFIRMATION_REQUIRED = 'gsc.confirmation_required';

    public const PROPERTY_ARCHIVED_STATE = 'gsc.property_archived';

    public const SYNC_LOCKED = 'gsc.sync_locked';

    public const FAILED = 'gsc.failed';
}
