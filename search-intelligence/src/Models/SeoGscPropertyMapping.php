<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SeoGscPropertyMapping extends Model
{
    protected $connection = 'mysql';

    protected $table = 'seo_gsc_property_mappings';

    protected $guarded = [];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SeoGscMasterConnection::class, 'gsc_connection_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
