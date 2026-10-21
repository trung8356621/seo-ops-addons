<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaJobEvent extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_job_events';

    protected $fillable = [
        'job_id', 'attempt_id', 'event_type', 'from_status', 'to_status', 'payload', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(SaJob::class, 'job_id');
    }
}
