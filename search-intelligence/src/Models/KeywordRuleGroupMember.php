<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

final class KeywordRuleGroupMember extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_rule_group_members';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'keyword_id' => 'integer',
        'group_id' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(KeywordRuleGroup::class, 'group_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
