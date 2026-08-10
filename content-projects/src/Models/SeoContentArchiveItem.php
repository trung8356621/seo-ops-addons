<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoContentArchiveItem extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_archive_items';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'article_id' => 'integer',
        'task_id' => 'integer',
        'from_project_id' => 'integer',
        'archived_by' => 'integer',
        'connected_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'task_id');
    }

    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'from_project_id');
    }

    public function archivedByUser(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'archived_by');
    }
}
