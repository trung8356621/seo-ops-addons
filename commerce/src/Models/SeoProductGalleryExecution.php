<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoProductGalleryExecution extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_product_gallery_executions';

    protected $fillable = [
        'execution_id',
        'article_id',
        'site_id',
        'generation_mode',
        'status',
        'parent_media_id',
        'planner_snapshot',
        'global_context_snapshot',
        'provider_snapshot',
        'original_media_snapshot_ids',
        'selection_snapshot',
        'failure_reason',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planner_snapshot' => 'array',
            'global_context_snapshot' => 'array',
            'provider_snapshot' => 'array',
            'original_media_snapshot_ids' => 'array',
            'selection_snapshot' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function childAttempts(): HasMany
    {
        return $this->hasMany(SeoProductGalleryChildAttempt::class, 'parent_execution_id');
    }
}
