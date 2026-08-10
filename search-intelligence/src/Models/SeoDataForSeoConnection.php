<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SeoDataForSeoConnection extends Model
{
    protected $connection = 'mysql';

    protected $table = 'seo_dataforseo_connections';

    protected $guarded = [];

    protected $casts = [
        'password' => 'encrypted',
        'metadata' => 'array',
        'last_checked_at' => 'datetime',
        'is_global' => 'boolean',
        'balance' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
