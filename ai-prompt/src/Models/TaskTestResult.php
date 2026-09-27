<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTestResult extends Model
{
    use UsesCoreDatabaseConnection;

    protected $guarded = [];

    protected $casts = [
        'input_snapshot' => 'array',
        'resolved_context' => 'array',
        'step_results' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(SeoTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
