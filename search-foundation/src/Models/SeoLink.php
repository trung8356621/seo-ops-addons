<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

class SeoLink extends Model
{
    public const TYPE_INTERNAL = 'internal';

    public const TYPE_EXTERNAL = 'external';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_links';

    protected $fillable = [
        'site_id',
        'url',
        'type',
        'article_id',
        'source_article_id',
        'is_nofollow',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'article_id' => 'integer',
        'source_article_id' => 'integer',
        'is_nofollow' => 'boolean',
    ];

    /**
     * @param  Builder<SeoLink>  $query
     * @return Builder<SeoLink>
     */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        if ($siteId <= 0) {
            return $query;
        }

        return $query->where('site_id', $siteId);
    }

    /**
     * @param  Builder<SeoLink>  $query
     * @return Builder<SeoLink>
     */
    public function scopeForCurrentSite(Builder $query): Builder
    {
        $siteId = SeoAccessControl::globalSiteId();

        return $siteId !== null && $siteId > 0
            ? $query->forSite($siteId)
            : $query;
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'keyword_link', 'link_id', 'keyword_id')
            ->using(KeywordLink::class)
            ->withPivot(['search_volume', 'difficulty', 'metrics'])
            ->withTimestamps();
    }

    public function sourceArticle(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'source_article_id');
    }

    public function targetArticle(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function primaryKeyword(): ?Keyword
    {
        if (! Schema::connection($this->getConnectionName())->hasTable('keyword_link')) {
            return null;
        }

        if ($this->relationLoaded('keywords')) {
            return $this->keywords->first();
        }

        return $this->keywords()->orderBy('keyword_link.keyword_id')->first();
    }

    public function anchorText(): string
    {
        return (string) ($this->primaryKeyword()?->phrase ?? '');
    }
}
