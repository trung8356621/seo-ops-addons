<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

final class SeoExtendedProviderConnection extends Model
{
    protected $connection = 'mysql';

    protected $table = 'seo_extended_provider_connections';

    protected $guarded = [];

    protected $casts = [
        'api_key' => 'encrypted',
        'metadata' => 'array',
        'is_global' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function isConfigured(): bool
    {
        return filled($this->api_key);
    }

    public function isActive(): bool
    {
        return $this->isConfigured() && (string) $this->status === 'active';
    }
}
