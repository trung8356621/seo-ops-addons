<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordMeta extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'keyword_meta';

    protected $fillable = [
        'keyword_id',
        'meta_key',
        'meta_value',
    ];

    protected function casts(): array
    {
        return [
            'keyword_id' => 'integer',
        ];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }
}
