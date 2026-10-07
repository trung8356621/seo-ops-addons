<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRunStatus;

/**
 * Persisted Topic grouping proposal / analysis run.
 * Not Topic membership. Not semantic vector storage.
 */
final class SeoTopicGroupingRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topic_grouping_runs';

    protected $fillable = [
        'site_id',
        'provider',
        'external_analysis_id',
        'input_hash',
        'plan_hash',
        'status',
        'keyword_count',
        'group_count',
        'unassigned_count',
        'low_confidence_count',
        'model',
        'model_version',
        'algorithm',
        'proposal_payload',
        'apply_plan_payload',
        'diagnostics',
        'error_code',
        'error_message',
        'apply_error_code',
        'apply_error_message',
        'started_at',
        'completed_at',
        'applied_at',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'keyword_count' => 'integer',
        'group_count' => 'integer',
        'unassigned_count' => 'integer',
        'low_confidence_count' => 'integer',
        'proposal_payload' => 'array',
        'apply_plan_payload' => 'array',
        'diagnostics' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function isProposalReady(): bool
    {
        return $this->status === TopicGroupingRunStatus::PROPOSAL_READY;
    }

    public function isFailed(): bool
    {
        return $this->status === TopicGroupingRunStatus::FAILED;
    }

    public function isStale(): bool
    {
        return $this->status === TopicGroupingRunStatus::STALE;
    }

    public function isApplied(): bool
    {
        return $this->status === TopicGroupingRunStatus::APPLIED;
    }

    public function canApply(): bool
    {
        return $this->status === TopicGroupingRunStatus::PROPOSAL_READY
            || $this->status === TopicGroupingRunStatus::APPLY_FAILED;
    }
}
