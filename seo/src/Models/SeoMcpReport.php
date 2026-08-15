<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Seo\Enums\McpReportStatus;

final class SeoMcpReport extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_mcp_reports';

    protected $guarded = [];

    protected $casts = [
        'period_id' => 'integer',
        'site_id' => 'integer',
        'revision' => 'integer',
        'site_snapshot_id' => 'integer',
        'keyword_snapshot_id' => 'integer',
        'overview_json' => 'array',
        'highlights_json' => 'array',
        'risks_json' => 'array',
        'opportunities_json' => 'array',
        'action_plan_json' => 'array',
        'ai_context_json' => 'array',
        'completed_sources' => 'integer',
        'total_sources' => 'integer',
        'last_activity_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(SeoMcpPeriod::class, 'period_id');
    }

    public function siteSnapshot(): BelongsTo
    {
        return $this->belongsTo(SeoMcpSourceSnapshot::class, 'site_snapshot_id');
    }

    public function keywordSnapshot(): BelongsTo
    {
        return $this->belongsTo(SeoMcpSourceSnapshot::class, 'keyword_snapshot_id');
    }

    public function statusEnum(): McpReportStatus
    {
        return McpReportStatus::tryFrom((string) $this->status) ?? McpReportStatus::Missing;
    }

    public function sourceCoverageReady(): int
    {
        $ready = 0;
        if ($this->siteSnapshot?->isUsable()) {
            $ready++;
        }
        if ($this->keywordSnapshot?->isUsable()) {
            $ready++;
        }

        return $ready;
    }
}
