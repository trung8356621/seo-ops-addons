<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\LinkIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;

/**
 * @deprecated Experimental V2 Link Intelligence persistence on omi_seo_ai.
 * Canonical workspace does not query this model.
 */
class LinkResource extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'link_resources';

    /** @var list<string> */
    protected $fillable = [
        'original_url',
        'normalized_url',
        'normalized_url_hash',
        'domain',
        'title',
        'description',
        'fetch_status',
        'fetched_at',
        'metadata_json',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    /** @return BelongsToMany<SeedingTopic, $this> */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(
            SeedingTopic::class,
            'seeding_topic_links',
            'link_resource_id',
            'topic_id',
        )->withTimestamps();
    }
}
