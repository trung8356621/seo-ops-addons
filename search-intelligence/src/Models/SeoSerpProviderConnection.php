<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SeoSerpProviderConnection extends Model
{
    protected $connection = 'mysql';

    protected $table = 'seo_serp_provider_connections';

    protected $guarded = [];

    protected $casts = [
        'api_key' => 'encrypted',
        'metadata' => 'array',
        'last_checked_at' => 'datetime',
        'last_rank_check_at' => 'datetime',
        'is_global' => 'boolean',
        'result_depth' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfigured(): bool
    {
        return filled($this->api_key);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->isConfigured();
    }
}
