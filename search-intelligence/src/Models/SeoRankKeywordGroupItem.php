<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SeoRankKeywordGroupItem extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_rank_keyword_group_items';

    protected $guarded = [];

    public function group(): BelongsTo
    {
        return $this->belongsTo(SeoRankKeywordGroup::class, 'group_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
