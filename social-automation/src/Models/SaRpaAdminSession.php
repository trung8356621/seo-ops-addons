<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Opaque RPA Admin user session. Token stored as SHA-256 hash only.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $token_hint
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
final class SaRpaAdminSession extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_rpa_admin_sessions';

    protected $fillable = [
        'user_id',
        'token_hash',
        'token_hint',
        'expires_at',
        'last_seen_at',
        'revoked_at',
        'client_label',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
