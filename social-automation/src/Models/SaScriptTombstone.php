<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * @property int $id
 * @property string $script_id
 * @property string $source
 * @property string|null $package_key
 * @property string|null $content_digest
 * @property int|null $removed_by_user_id
 * @property \Illuminate\Support\Carbon $removed_at
 * @property \Illuminate\Support\Carbon|null $restored_at
 */
final class SaScriptTombstone extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_script_tombstones';

    protected $fillable = [
        'script_id',
        'source',
        'package_key',
        'content_digest',
        'removed_by_user_id',
        'removed_at',
        'restored_at',
    ];

    protected function casts(): array
    {
        return [
            'removed_by_user_id' => 'integer',
            'removed_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }
}
