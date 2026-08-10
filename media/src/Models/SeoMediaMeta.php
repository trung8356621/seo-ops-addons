<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoMediaMeta extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_media_meta';

    protected $guarded = [];

    public function media(): BelongsTo
    {
        return $this->belongsTo(SeoMedia::class, 'media_id');
    }
}

