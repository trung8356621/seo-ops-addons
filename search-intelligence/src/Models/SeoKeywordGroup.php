<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;

/**
 * Site-scoped keyword semantic/SEO family. Not a Topic.
 */
final class SeoKeywordGroup extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_groups';

    protected $fillable = [
        'site_id',
        'name',
        'source',
        'representative_keyword_id',
        'semantic_group_ref',
        'algorithm',
        'input_hash',
        'is_locked',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'representative_keyword_id' => 'integer',
        'is_locked' => 'boolean',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(SeoKeywordGroupKeyword::class, 'group_id');
    }

    public function topics(): HasMany
    {
        return $this->hasMany(SeoTopic::class, 'keyword_group_id');
    }

    public function representativeKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'representative_keyword_id');
    }

    public function isProtectedFromSemanticRefresh(): bool
    {
        return $this->is_locked || KeywordGroupSource::isManual($this->source);
    }
}
