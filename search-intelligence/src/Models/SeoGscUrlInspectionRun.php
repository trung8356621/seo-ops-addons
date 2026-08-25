<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GSC URL Inspection batch run (operation state — not Index Health).
 *
 * @property int $id
 * @property string $public_ref
 * @property int $site_id
 * @property string $property_uri
 * @property string $status
 * @property int $requested
 * @property int $inspected
 * @property int $indexed
 * @property int $not_indexed
 * @property int $unknown
 * @property int $failed
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $created_by
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property array<string, mixed>|null $meta
 */
final class SeoGscUrlInspectionRun extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_gsc_url_inspection_runs';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'requested' => 'integer',
        'inspected' => 'integer',
        'indexed' => 'integer',
        'not_indexed' => 'integer',
        'unknown' => 'integer',
        'failed' => 'integer',
        'created_by' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    /** @return HasMany<SeoGscUrlInspectionRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SeoGscUrlInspectionRunItem::class, 'run_id');
    }
}
