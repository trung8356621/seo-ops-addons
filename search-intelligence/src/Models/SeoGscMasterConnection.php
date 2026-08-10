<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SeoGscMasterConnection extends Model
{
    protected $connection = 'mysql';

    protected $table = 'seo_gsc_master_connections';

    protected $guarded = [];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'oauth_client_secret' => 'encrypted',
        'metadata' => 'array',
        'last_checked_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_global' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function propertyMappings(): HasMany
    {
        return $this->hasMany(SeoGscPropertyMapping::class, 'gsc_connection_id');
    }
}
