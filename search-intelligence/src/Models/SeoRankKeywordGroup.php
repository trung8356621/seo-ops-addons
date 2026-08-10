<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SeoRankKeywordGroup extends Model
{
    use BelongsToOnDefaultConnection;
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_rank_keyword_groups';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SeoRankKeywordGroupItem::class, 'group_id');
    }

    public function activeItems(): HasMany
    {
        return $this->items();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAccessible(Builder $query): Builder
    {
        if (! \Omnichannel\Addons\Seo\Support\SeoAccessControl::shouldScopeToAccountOwner()) {
            return $query;
        }

        return $query->where('created_by', \Omnichannel\Addons\Seo\Support\SeoAccessControl::accountSiteOwnerId());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function summaryLabel(): string
    {
        $parts = array_filter([
            $this->country_code !== '' ? strtoupper((string) $this->country_code) : null,
            $this->device !== '' ? ucfirst((string) $this->device) : null,
        ]);

        $context = $parts !== [] ? implode(' · ', $parts) : '';

        return $context !== '' ? "{$this->name} — {$context}" : (string) $this->name;
    }
}
