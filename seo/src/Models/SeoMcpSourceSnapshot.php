<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Seo\Enums\McpSnapshotStatus;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;

final class SeoMcpSourceSnapshot extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_mcp_source_snapshots';

    protected $guarded = [];

    protected $casts = [
        'period_id' => 'integer',
        'site_id' => 'integer',
        'generated_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'metrics_json' => 'array',
        'summary_json' => 'array',
        'context_json' => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(SeoMcpPeriod::class, 'period_id');
    }

    public function sourceKey(): ?McpSourceKey
    {
        return McpSourceKey::tryFrom((string) $this->source);
    }

    public function statusEnum(): McpSnapshotStatus
    {
        return McpSnapshotStatus::tryFrom((string) $this->status) ?? McpSnapshotStatus::Failed;
    }

    public function isUsable(): bool
    {
        return $this->statusEnum() !== McpSnapshotStatus::Failed
            && is_array($this->metrics_json);
    }

    /**
     * @return array<string, mixed>
     */
    public function preparedPayload(): array
    {
        $source = $this->sourceKey();

        return [
            'schema' => $source?->schema() ?? ((string) $this->source).'.mcp.v1',
            'period' => $this->period?->periodKey(),
            'site_id' => (int) $this->site_id,
            'source' => (string) $this->source,
            'status' => (string) $this->status,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'source_updated_at' => $this->source_updated_at?->toIso8601String(),
            'content_hash' => (string) ($this->content_hash ?? ''),
            'metrics' => is_array($this->metrics_json) ? $this->metrics_json : [],
            'summary' => is_array($this->summary_json) ? $this->summary_json : [],
            'context' => is_array($this->context_json) ? $this->context_json : [],
        ];
    }
}
