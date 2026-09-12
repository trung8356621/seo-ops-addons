<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ApiConnection;

final class AiRuntimeHealthState extends Model
{
    use UsesCoreDatabaseConnection;

    public const SUBJECT_CONNECTION = 'connection';

    public const SUBJECT_MODEL = 'model';

    protected $table = 'ai_runtime_health_states';

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'api_connection_id',
        'health_status',
        // Deprecated non-authoritative mirror — SSOT is api_connections.paid_locked + paid_lock_reasons.
        'paid_locked',
        'manual_unlock_required',
        'cooldown_until',
        'total_attempts',
        'success_count',
        'failure_count',
        'consecutive_failures',
        'failure_counts',
        'last_error_code',
        'last_failure_class',
        'last_failure_message',
        'last_failure_at',
        'last_success_at',
    ];

    protected $casts = [
        'paid_locked' => 'boolean',
        'manual_unlock_required' => 'boolean',
        'cooldown_until' => 'datetime',
        'failure_counts' => 'array',
        'last_failure_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];

    public function apiConnection(): BelongsTo
    {
        return $this->belongsTo(ApiConnection::class, 'api_connection_id');
    }

    public static function subjectKey(string $subjectType, int $subjectId): string
    {
        return $subjectType.':'.$subjectId;
    }
}
