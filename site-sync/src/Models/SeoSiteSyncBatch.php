<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Models;

use Illuminate\Database\Eloquent\Model;

class SeoSiteSyncBatch extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_batches';

    protected $fillable = [
        'site_id',
        'run_id',
        'checksum',
        'mode',
        'cursor',
        'has_more',
        'payload_json',
        'applied_at',
    ];

    protected $casts = [
        'has_more' => 'boolean',
        'applied_at' => 'datetime',
    ];

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        $decoded = json_decode((string) $this->payload_json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
