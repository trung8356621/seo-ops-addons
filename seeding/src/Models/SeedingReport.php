<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

class SeedingReport extends Model
{
    protected $connection = SeedingServiceConfig::CONNECTION;

    protected $table = 'seeding_reports';

    /** @var list<string> */
    protected $fillable = [
        'topic_id',
        'user_id',
        'user_display_name',
        'comment_text',
        'seed_link_id',
        'seed_url',
        'proof_path',
        'proof_mime',
        'proof_meta',
        'reported_at',
    ];

    protected $casts = [
        'topic_id' => 'integer',
        'user_id' => 'integer',
        'proof_meta' => 'array',
        'reported_at' => 'datetime',
    ];

    /** @return BelongsTo<SeedingTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(SeedingTopic::class, 'topic_id');
    }
}
