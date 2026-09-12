<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

class WebsiteShareReport extends Model
{
    protected $connection = SeedingServiceConfig::CONNECTION;

    protected $table = 'website_share_reports';

    /** @var list<string> */
    protected $fillable = [
        'job_id',
        'target_id',
        'user_id',
        'user_display_name',
        'social',
        'share_text',
        'proof_path',
        'proof_mime',
        'proof_meta',
        'reported_at',
    ];

    protected $casts = [
        'job_id' => 'integer',
        'target_id' => 'integer',
        'user_id' => 'integer',
        'social' => SeedingSocialPlatform::class,
        'proof_meta' => 'array',
        'reported_at' => 'datetime',
    ];

    /** @return BelongsTo<WebsiteShareJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(WebsiteShareJob::class, 'job_id');
    }

    /** @return BelongsTo<WebsiteShareTarget, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(WebsiteShareTarget::class, 'target_id');
    }
}
