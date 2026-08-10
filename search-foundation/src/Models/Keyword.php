<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    public const TYPE_NORMAL = 'normal';

    public const TYPE_SUGGEST = 'suggest';

    public const TYPE_FREE = 'free';

    /**
     * @return list<string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_NORMAL,
            self::TYPE_SUGGEST,
            self::TYPE_FREE,
        ];
    }

    public static function isNormalType(?string $type): bool
    {
        return in_array((string) $type, [self::TYPE_NORMAL, 'focus', 'internal'], true);
    }

    public const METRIC_RESCRAPE_KEEP = 'rescrape_keep';

    public const PHRASE_MAX_LENGTH = 255;

    protected $connection = 'omi_seo_ai';

    protected $fillable = [
        'phrase',
        'type',
        'parent_id',
        'source',
        'source_locked',
        'review_status',
        'review_reason_id',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'source_locked' => 'boolean',
        'review_reason_id' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public static function decodePhrase(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Rank Math / Yoast có thể lưu nhiều focus keyword phân tách bằng dấu phẩy — chỉ lấy cụm chính (đầu tiên).
     */
    public static function normalizeFocusPhrase(?string $value): string
    {
        $value = self::decodePhrase($value);
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/[,，;；|]/u', $value) ?: [];
        foreach ($parts as $part) {
            $part = self::decodePhrase(is_string($part) ? $part : '');
            if ($part !== '') {
                return $part;
            }
        }

        return $value;
    }

    public static function clampPhrase(?string $value, ?int $maxLength = null): string
    {
        $value = self::decodePhrase($value);
        if ($value === '') {
            return '';
        }

        $maxLength = $maxLength ?? self::PHRASE_MAX_LENGTH;
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength);
    }

    public static function preparePhraseForStorage(?string $value): string
    {
        return self::clampPhrase(self::normalizeFocusPhrase($value));
    }

    protected function phrase(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): string => self::decodePhrase($value),
            set: fn (?string $value): string => self::preparePhraseForStorage($value),
        );
    }

    protected static function booted(): void
    {
        static::saving(function (Keyword $keyword): void {
            if (! $keyword->isDirty('phrase')) {
                return;
            }

            $raw = $keyword->getAttributes()['phrase'] ?? '';
            $keyword->attributes['phrase'] = self::preparePhraseForStorage(is_string($raw) ? $raw : '');
        });
    }

    public function getNameAttribute(): string
    {
        return (string) $this->phrase;
    }

    public function getKeywordAttribute(): string
    {
        return (string) $this->phrase;
    }

    public function getVolumeAttribute(): ?int
    {
        $siteId = $this->resolveSiteId();
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        return app(KeywordMetaRepository::class)->getSiteSearchVolume((int) $this->id, $siteId);
    }

    public function getSearchVolumeAttribute(): ?int
    {
        return $this->volume;
    }

    public function getSiteIdAttribute(): ?int
    {
        return $this->resolveSiteId();
    }

    public function getTargetUrlAttribute(): ?string
    {
        return $this->targetUrlForSite((int) (SeoAccessControl::globalSiteId() ?? 0));
    }

    public function getMetricsAttribute(): ?array
    {
        $siteId = $this->resolveSiteId();
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        if (! app(KeywordMetaRepository::class)->keepOnRescrapeForSite($this, $siteId)) {
            return null;
        }

        return [self::METRIC_RESCRAPE_KEEP => true];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function linkMaps(): HasMany
    {
        return $this->hasMany(SeoLinkMap::class, 'keyword_id');
    }

    public function linkMapsForSite(int $siteId): HasMany
    {
        return $this->linkMaps()->whereHas(
            'sourceArticle',
            static fn (Builder $query): Builder => $query->where('site_id', $siteId),
        );
    }

    /**
     * @return array<string, \Closure(Builder): Builder>
     */
    public static function linkMapCountRelations(): array
    {
        return [
            'linkMaps as linked_articles_count' => static function (Builder $query): Builder {
                return $query
                    ->whereNotNull('source_article_id')
                    ->whereHas(
                        'sourceArticle',
                        static fn (Builder $articleQuery): Builder => $articleQuery->whereNull('deleted_at'),
                    );
            },
            'linkMaps as site_links_count' => static function (Builder $query): Builder {
                return $query->where('status', '!=', SeoLinkMapStatus::Ignored->value);
            },
        ];
    }

    public function metas(): HasMany
    {
        return $this->hasMany(KeywordMeta::class);
    }

    public function metaValue(string $metaKey): ?string
    {
        if ($this->relationLoaded('metas')) {
            $match = $this->metas->first(
                static fn (KeywordMeta $meta): bool => $meta->meta_key === $metaKey,
            );

            return $match instanceof KeywordMeta ? $match->meta_value : null;
        }

        $value = $this->metas()->where('meta_key', $metaKey)->value('meta_value');

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<int>
     */
    public function getTagIdsList(): array
    {
        return app(KeywordMetaRepository::class)->getTagIds((int) $this->id);
    }

    /**
     * @return list<string>
     */
    public function getQualityFlagsList(): array
    {
        $status = KeywordReviewStatus::tryFrom((string) ($this->review_status ?? ''));
        if ($status === KeywordReviewStatus::Warning) {
            return ['warning'];
        }

        if ($status === KeywordReviewStatus::Danger) {
            return ['danger'];
        }

        return [];
    }

    public function reviewStatusEnum(): KeywordReviewStatus
    {
        return KeywordReviewStatus::tryFrom((string) ($this->review_status ?? ''))
            ?? KeywordReviewStatus::Active;
    }

    public function isReviewNegative(): bool
    {
        return $this->reviewStatusEnum()->isNegative();
    }

    public function reviewReason(): BelongsTo
    {
        return $this->belongsTo(KeywordReviewReason::class, 'review_reason_id');
    }

    public function reviewHistories(): HasMany
    {
        return $this->hasMany(KeywordReviewHistory::class, 'keyword_id');
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function scopeReviewActive(Builder $query): Builder
    {
        return $query->where('review_status', KeywordReviewStatus::Active->value);
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function scopeReviewWarning(Builder $query): Builder
    {
        return $query->where('review_status', KeywordReviewStatus::Warning->value);
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function scopeReviewDanger(Builder $query): Builder
    {
        return $query->where('review_status', KeywordReviewStatus::Danger->value);
    }

    public function mainArticleId(): ?int
    {
        return app(KeywordMetaRepository::class)->getMainArticleId((int) $this->id);
    }

    public function mainArticles(): BelongsToMany
    {
        return $this->belongsToMany(
            SeoArticle::class,
            'keyword_meta',
            'keyword_id',
            'meta_value',
        )
            ->where('keyword_meta.meta_key', KeywordMetaKey::MainArticleId->value)
            ->whereColumn('articles.id', 'keyword_meta.meta_value');
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  list<int>  $tagIds
     * @return Builder<Keyword>
     */
    public function scopeWhereHasAnyTagId(Builder $query, array $tagIds): Builder
    {
        $tagIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $tagIds),
            static fn (int $id): bool => $id > 0,
        ));

        if ($tagIds === []) {
            return $query;
        }

        return $query->whereHas(
            'metas',
            static function (Builder $metaQuery) use ($tagIds): void {
                $metaQuery
                    ->where('meta_key', KeywordMetaKey::Tags->value)
                    ->where(function (Builder $containsQuery) use ($tagIds): void {
                        foreach ($tagIds as $tagId) {
                            $containsQuery->orWhereRaw(
                                'JSON_CONTAINS(meta_value, ?, "$")',
                                [json_encode($tagId, JSON_THROW_ON_ERROR)],
                            );
                        }
                    });
            },
        );
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  list<int>  $tagIds
     * @return Builder<Keyword>
     */
    public function scopeWhereMissingAnyTagId(Builder $query, array $tagIds): Builder
    {
        $tagIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $tagIds),
            static fn (int $id): bool => $id > 0,
        ));

        if ($tagIds === []) {
            return $query;
        }

        return $query->whereDoesntHave(
            'metas',
            static function (Builder $metaQuery) use ($tagIds): void {
                $metaQuery
                    ->where('meta_key', KeywordMetaKey::Tags->value)
                    ->where(function (Builder $containsQuery) use ($tagIds): void {
                        foreach ($tagIds as $tagId) {
                            $containsQuery->orWhereRaw(
                                'JSON_CONTAINS(meta_value, ?, "$")',
                                [json_encode($tagId, JSON_THROW_ON_ERROR)],
                            );
                        }
                    });
            },
        );
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  list<string>  $flags
     * @return Builder<Keyword>
     */
    public function scopeWhereHasAnyQualityFlag(Builder $query, array $flags): Builder
    {
        $flags = array_values(array_filter(
            array_map(static fn (mixed $flag): string => trim((string) $flag), $flags),
            static fn (string $flag): bool => in_array($flag, ['danger', 'warning'], true),
        ));

        if ($flags === []) {
            return $query;
        }

        $statuses = array_map(
            static fn (string $flag): string => $flag === 'danger'
                ? KeywordReviewStatus::Danger->value
                : KeywordReviewStatus::Warning->value,
            $flags,
        );

        return $query->whereIn('review_status', array_values(array_unique($statuses)));
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function scopeWhereHasNoQualityFlags(Builder $query): Builder
    {
        return $query->where('review_status', KeywordReviewStatus::Active->value);
    }

    /**
     * @deprecated Pivot keyword_tag removed; use getTagIdsList().
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'keyword_tag', 'keyword_id', 'tag_id');
    }

    public function resolveSiteId(?int $preferredSiteId = null): ?int
    {
        if ($preferredSiteId !== null && $preferredSiteId > 0) {
            return $preferredSiteId;
        }

        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null && $globalSiteId > 0) {
            return $globalSiteId;
        }

        if ($this->relationLoaded('linkMaps') && $this->linkMaps->isNotEmpty()) {
            $first = $this->linkMaps->first();
            $siteId = $first?->sourceArticle?->site_id ?? null;

            return is_numeric($siteId) ? (int) $siteId : null;
        }

        $siteId = $this->linkMaps()
            ->join('articles', 'articles.id', '=', 'seo_link_maps.source_article_id')
            ->orderBy('seo_link_maps.id')
            ->value('articles.site_id');

        if (is_numeric($siteId)) {
            return (int) $siteId;
        }

        if ($this->relationLoaded('metas')) {
            foreach ($this->metas as $meta) {
                if (! $meta instanceof KeywordMeta) {
                    continue;
                }

                $siteIdFromKey = KeywordMetaKey::siteIdFromKey((string) $meta->meta_key);
                if ($siteIdFromKey !== null) {
                    return $siteIdFromKey;
                }
            }
        } else {
            $firstSiteKey = $this->metas()
                ->where('meta_key', 'like', 'site.%')
                ->orderBy('id')
                ->value('meta_key');

            if (is_string($firstSiteKey)) {
                $siteIdFromKey = KeywordMetaKey::siteIdFromKey($firstSiteKey);
                if ($siteIdFromKey !== null) {
                    return $siteIdFromKey;
                }
            }
        }

        $mainArticleId = $this->mainArticleId();
        if ($mainArticleId !== null) {
            $mainSiteId = SeoArticle::query()->whereKey($mainArticleId)->value('site_id');
            if (is_numeric($mainSiteId)) {
                return (int) $mainSiteId;
            }
        }

        return null;
    }

    public function targetUrlForSite(int $siteId): ?string
    {
        if ($siteId <= 0) {
            return null;
        }

        $siteMetaUrl = trim((string) (app(KeywordMetaRepository::class)->getSiteTargetUrl((int) $this->id, $siteId) ?? ''));
        if ($siteMetaUrl !== '') {
            return $siteMetaUrl;
        }

        return $this->targetUrlFromLinkMaps($siteId);
    }

    private function targetUrlFromLinkMaps(int $siteId): ?string
    {
        $map = $this->linkMapsForSite($siteId)
            ->with('targetArticle:id,site_id,title,slug')
            ->orderBy('seo_link_maps.id')
            ->first();

        if (! $map instanceof SeoLinkMap) {
            return null;
        }

        $external = trim((string) ($map->target_external_url ?? ''));
        if ($external !== '') {
            return $external;
        }

        if ((int) ($map->target_article_id ?? 0) <= 0) {
            return null;
        }

        $target = $map->relationLoaded('targetArticle')
            ? $map->targetArticle
            : $map->targetArticle()->first(['id', 'site_id', 'title', 'slug']);

        if (! $target instanceof SeoArticle) {
            return null;
        }

        $url = trim(app(WordPressArticleContentService::class)->resolvePermalink($target));

        return $url !== '' ? $url : null;
    }

    public function hasSiteContext(int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        if ($this->relationLoaded('linkMaps')) {
            if ($this->linkMaps->contains(
                static fn (SeoLinkMap $map): bool => (int) ($map->sourceArticle?->site_id ?? 0) === $siteId,
            )) {
                return true;
            }
        } elseif ($this->linkMapsForSite($siteId)->exists()) {
            return true;
        }

        if (app(KeywordMetaRepository::class)->keywordHasSiteMeta((int) $this->id, $siteId)) {
            return true;
        }

        $mainArticleId = $this->mainArticleId();
        if ($mainArticleId !== null) {
            return SeoArticle::query()
                ->whereKey($mainArticleId)
                ->where('site_id', $siteId)
                ->exists();
        }

        return false;
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        if ($siteId <= 0) {
            return $query;
        }

        return $query->where(function (Builder $scopeQuery) use ($siteId): void {
            $scopeQuery
                ->whereHas(
                    'linkMaps',
                    static fn (Builder $mapQuery): Builder => $mapQuery->whereHas(
                        'sourceArticle',
                        static fn (Builder $articleQuery): Builder => $articleQuery->where('site_id', $siteId),
                    ),
                )
                ->orWhereHas(
                    'metas',
                    static fn (Builder $metaQuery): Builder => $metaQuery->where('meta_key', 'like', "site.{$siteId}.%"),
                )
                ->orWhereHas(
                    'metas',
                    static fn (Builder $metaQuery): Builder => $metaQuery
                        ->where('meta_key', KeywordMetaKey::MainArticleId->value)
                        ->whereIn('meta_value', SeoArticle::query()
                            ->where('site_id', $siteId)
                            ->select('id')),
                );
        });
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  list<int>  $siteIds
     * @return Builder<Keyword>
     */
    public function scopeForSites(Builder $query, array $siteIds): Builder
    {
        $siteIds = array_values(array_filter(
            $siteIds,
            static fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0,
        ));

        if ($siteIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $scopeQuery) use ($siteIds): void {
            $scopeQuery
                ->whereHas(
                    'linkMaps',
                    static fn (Builder $mapQuery): Builder => $mapQuery->whereHas(
                        'sourceArticle',
                        static fn (Builder $articleQuery): Builder => $articleQuery->whereIn('site_id', $siteIds),
                    ),
                )
                ->orWhereHas(
                    'metas',
                    static function (Builder $metaQuery) use ($siteIds): Builder {
                        return $metaQuery->where(function (Builder $siteMetaQuery) use ($siteIds): void {
                            foreach ($siteIds as $siteId) {
                                $siteMetaQuery->orWhere('meta_key', 'like', "site.{$siteId}.%");
                            }
                        });
                    },
                )
                ->orWhereHas(
                    'metas',
                    static fn (Builder $metaQuery): Builder => $metaQuery
                        ->where('meta_key', KeywordMetaKey::MainArticleId->value)
                        ->whereIn('meta_value', SeoArticle::query()
                            ->whereIn('site_id', $siteIds)
                            ->select('id')),
                );
        });
    }

    public function keepOnRescrapeForSite(int $siteId): bool
    {
        return app(KeywordMetaRepository::class)->keepOnRescrapeForSite($this, $siteId);
    }
}
