<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class KeywordRuleGroup extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_rule_groups';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(KeywordRuleGroupRule::class, 'group_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(KeywordRuleGroupMember::class, 'group_id');
    }
}
