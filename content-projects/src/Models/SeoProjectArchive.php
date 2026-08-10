<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoProjectArchive extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_archives';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'site_id' => 'integer',
        'owner_id' => 'integer',
        'archived_by' => 'integer',
        'project_month' => 'integer',
        'project_year' => 'integer',
        'articles_count' => 'integer',
        'total_articles' => 'integer',
        'completed_articles' => 'integer',
        'approved_articles' => 'integer',
        'synced_articles' => 'integer',
        'average_seo_score' => 'float',
        'summary_snapshot' => 'array',
        'archived_at' => 'datetime',
        'restored_at' => 'datetime',
        'restored_by' => 'integer',
    ];

    /**
     * @param  Builder<SeoProjectArchive>  $query
     * @return Builder<SeoProjectArchive>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('restored_at');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'owner_id');
    }

    public function archivedByUser(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'archived_by');
    }

    public function restoredByUser(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'restored_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SeoProjectArchiveItem::class, 'seo_project_archive_id');
    }
}
