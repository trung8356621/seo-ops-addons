<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\Seo\Enums\McpPeriodStatus;

final class SeoMcpPeriod extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_mcp_periods';

    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'opened_at' => 'datetime',
        'finalized_at' => 'datetime',
        'finalized_by' => 'integer',
        'manual_finalized' => 'boolean',
        'expected_sites' => 'integer',
        'available_sites' => 'integer',
    ];

    public function periodKey(): string
    {
        return sprintf('%04d-%02d', (int) $this->year, (int) $this->month);
    }

    public function statusEnum(): McpPeriodStatus
    {
        return McpPeriodStatus::tryFrom((string) $this->status) ?? McpPeriodStatus::Open;
    }

    public function isOpen(): bool
    {
        return $this->statusEnum()->isOpen();
    }

    public function isFinalized(): bool
    {
        return $this->statusEnum() === McpPeriodStatus::Finalized;
    }

    public function coverageKind(): string
    {
        $expected = max(0, (int) $this->expected_sites);
        $available = max(0, (int) $this->available_sites);
        if ($expected <= 0 || $available >= $expected) {
            return 'complete';
        }

        return 'partial';
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SeoMcpSourceSnapshot::class, 'period_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(SeoMcpReport::class, 'period_id');
    }
}
