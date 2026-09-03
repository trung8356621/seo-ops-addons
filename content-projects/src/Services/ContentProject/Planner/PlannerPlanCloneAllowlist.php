<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner;

/**
 * Explicit allowlist for Project Planner configuration clone.
 * Generated content / runs / metrics are never included.
 */
final class PlannerPlanCloneAllowlist
{
    public const MODE_SKIP_EXISTING = 'skip_existing';

    public const MODE_MERGE_MISSING = 'merge_missing';

    /** Top-level planner fields that may be cloned. */
    public const PLAN_FIELDS = [
        'content_type',
        'post_type',
        'note_items',
        // quantity is derived from note_items target sum — recomputed, not copied as counter
    ];

    /** Per note-item fields that may be cloned (after Topic remap). */
    public const NOTE_ITEM_FIELDS = [
        'source_type',
        'cluster_name_snapshot',
        'seed_text',
        'target_dna_count',
        'target_mode',
        'dna',
        // cluster_ref remapped — never raw-copied from source for cluster topics
    ];

    /** DNA row fields. */
    public const DNA_FIELDS = [
        'phrase',
        'slots',
        'source',
        'placement',
    ];

    /**
     * Absolute deny — never clone these keys from any payload.
     *
     * @var list<string>
     */
    public const DENY_KEYS = [
        'site_id',
        'cluster_id',
        'topic_id',
        'keyword_id',
        'article_id',
        'origin_id',
        'planner_run_id',
        'prompt_result_id',
        'task_ids',
        'candidates',
        'added',
        'generated',
        'remaining',
        'status',
        'stop_reason',
        'completion_kind',
        'mcp_share_snapshot', // destination recomputes / leaves null for manual
        'article_count',
        'dna_count',
        'specified',
        'missing',
        '_batch_trace',
        'chunk_ledger',
        'routing',
        'fill_remaining_of_run_id',
    ];
}
