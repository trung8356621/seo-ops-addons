<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordWorkspaceStatus;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoKeywordWorkspace extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_workspaces';

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'status' => KeywordWorkspaceStatus::class,
        'keyword_count' => 'integer',
        'cluster_count' => 'integer',
        'topic_count' => 'integer',
        'last_analyzed_at' => 'datetime',
        'archived_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'settings' => 'array',
        'summary' => 'array',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /** @return HasMany<SeoKiKeyword, $this> */
    public function keywords(): HasMany
    {
        return $this->hasMany(SeoKiKeyword::class, 'workspace_id');
    }

    /** @return HasMany<SeoKeywordCluster, $this> */
    public function clusters(): HasMany
    {
        return $this->hasMany(SeoKeywordCluster::class, 'workspace_id');
    }

    /** @return HasMany<SeoKiTopic, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(SeoKiTopic::class, 'workspace_id');
    }

    /** @return HasMany<SeoTopicalMapVersion, $this> */
    public function topicalMapVersions(): HasMany
    {
        return $this->hasMany(SeoTopicalMapVersion::class, 'workspace_id');
    }

    /** @return HasMany<SeoKeywordAnalysisOperation, $this> */
    public function analysisOperations(): HasMany
    {
        return $this->hasMany(SeoKeywordAnalysisOperation::class, 'workspace_id');
    }
}
