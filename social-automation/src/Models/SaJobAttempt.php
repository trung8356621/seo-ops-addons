<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaJobAttempt extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_job_attempts';

    protected $fillable = [
        'attempt_uuid', 'job_id', 'attempt_no', 'status', 'started_at', 'finished_at', 'result', 'error_code',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'attempt_no' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(SaJob::class, 'job_id');
    }
}
