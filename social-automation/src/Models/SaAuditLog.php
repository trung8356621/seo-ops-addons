<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaAuditLog extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_audit_logs';

    protected $fillable = [
        'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'context', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
