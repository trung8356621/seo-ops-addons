<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoProductGalleryChildAttempt extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_product_gallery_child_attempts';

    protected $fillable = [
        'execution_id',
        'parent_execution_id',
        'parent_media_id',
        'slot_index',
        'shot_key',
        'shot_definition_snapshot',
        'attempt',
        'status',
        'generated_media_id',
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
            'shot_definition_snapshot' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function parentExecution(): BelongsTo
    {
        return $this->belongsTo(SeoProductGalleryExecution::class, 'parent_execution_id');
    }
}
