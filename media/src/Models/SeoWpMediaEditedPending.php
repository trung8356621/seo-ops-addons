<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoWpMediaEditedPending extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_wp_media_edited_pending';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'wp_attachment_id' => 'integer',
            'seo_media_id' => 'integer',
            'edited_at' => 'datetime',
        ];
    }

    public function seoMedia(): BelongsTo
    {
        return $this->belongsTo(SeoMedia::class, 'seo_media_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }
}
