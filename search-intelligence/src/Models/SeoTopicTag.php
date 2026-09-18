<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Site-scoped Topic custom tag vocabulary (immutable: create/delete only).
 */
final class SeoTopicTag extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topic_tags';

    public const UPDATED_AT = null;

    protected $fillable = [
        'site_id',
        'name',
        'slug',
    ];

    protected $casts = [
        'site_id' => 'integer',
    ];

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(
            SeoTopic::class,
            'seo_topic_tag_assignments',
            'tag_id',
            'topic_id',
        );
    }
}
