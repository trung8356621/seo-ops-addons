<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KeywordRuleGroupRule extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_rule_group_rules';

    protected $guarded = [];

    protected $casts = [
        'group_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(KeywordRuleGroup::class, 'group_id');
    }
}
