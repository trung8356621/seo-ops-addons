<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaAccount extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_accounts';

    protected $fillable = [
        'account_uuid', 'platform', 'domain', 'label', 'declared_username', 'declared_email',
        'platform_account_id', 'identity_source', 'verified', 'last_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'last_confirmed_at' => 'datetime',
        ];
    }
}
