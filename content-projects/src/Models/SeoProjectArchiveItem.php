<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoProjectArchiveItem extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_archive_items';

    protected $guarded = [];

    protected $casts = [
        'seo_project_archive_id' => 'integer',
        'article_id' => 'integer',
        'task_id' => 'integer',
        'position' => 'integer',
        'article_snapshot' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function archive(): BelongsTo
    {
        return $this->belongsTo(SeoProjectArchive::class, 'seo_project_archive_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'task_id');
    }
}
