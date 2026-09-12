<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

class WebsiteShareTarget extends Model
{
    protected $connection = SeedingServiceConfig::CONNECTION;

    protected $table = 'website_share_targets';

    /** @var list<string> */
    protected $fillable = [
        'job_id',
        'social',
        'target_count',
        'completed_count',
    ];

    protected $casts = [
        'job_id' => 'integer',
        'social' => SeedingSocialPlatform::class,
        'target_count' => 'integer',
        'completed_count' => 'integer',
    ];

    /** @return BelongsTo<WebsiteShareJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(WebsiteShareJob::class, 'job_id');
    }

    public function isComplete(): bool
    {
        return (int) $this->completed_count >= max(1, (int) $this->target_count);
    }
}
