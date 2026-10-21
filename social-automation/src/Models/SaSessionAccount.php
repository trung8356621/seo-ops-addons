<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaSessionAccount extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_session_accounts';

    protected $fillable = [
        'browser_session_id', 'account_id', 'domain', 'is_active', 'bound_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'bound_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaBrowserSession, $this> */
    public function browserSession(): BelongsTo
    {
        return $this->belongsTo(SaBrowserSession::class, 'browser_session_id');
    }

    /** @return BelongsTo<SaAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SaAccount::class, 'account_id');
    }
}
