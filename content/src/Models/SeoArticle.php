<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models;

use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * Bản ghi nội dung SEO trên DB addon (bảng `articles`, connection `omi_seo_ai`).
 */
class SeoArticle extends Model
{
    use BelongsToOnDefaultConnection;
    use Concerns\RoutesArticleExtensionAttributes;
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    /** @var string Bảng vật lý là `articles` (SEO / bài viết đồng bộ). */
    protected $table = 'articles';

    protected $guarded = [];

    protected $casts = [
        'blocks' => 'array',
        'editor_document' => 'array',
        'reviewed_at' => 'datetime',
        'review_status' => 'string',
        'last_manual_saved_at' => 'datetime',
        'last_ai_content_at' => 'datetime',
        'document_version' => 'integer',
        'editor_document_schema_version' => 'integer',
        'editor_document_updated_at' => 'datetime',
        // Extension casts kept for attribute hydration until callers use relations.
        'seo_score' => 'decimal:2',
        'skip_seo_score' => 'boolean',
        'content_archived_at' => 'datetime',
        'content_archived_by' => 'integer',
        'published_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'indexed_at' => 'datetime',
        'previous_indexed_at' => 'datetime',
    ];

    /**
     * Persist canonical language codes only — never UI labels.
     * Runs on forceFill / setAttribute even when saveQuietly skips model events.
     */
    public function setLanguageAttribute(mixed $value): void
    {
        $this->attributes['language'] = ArticleLanguageCode::normalizeForStorage(
            $value === null ? null : (string) $value,
        );
    }

    /**
     * saveQuietly skips model events — still route extension attributes.
     */
    public function saveQuietly(array $options = [])
    {
        $this->captureAndStripExtensionAttributes();
        $saved = parent::saveQuietly($options);
        $this->flushPendingExtensionWrites();

        return $saved;
    }
    protected static function booted(): void
    {
        static::creating(static function (SeoArticle $article): void {
            try {
                app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleDocumentVersionService::class)
                    ->ensureDefaultOnCreate($article);
            } catch (\Throwable) {
                if ($article->document_version === null) {
                    $article->document_version = 1;
                }
            }
        });

        static::updating(static function (SeoArticle $article): void {
            try {
                app(\Omnichannel\Addons\Content\Services\ArticleEditor\ArticleDocumentVersionService::class)
                    ->bumpIfBodyChanging($article);
            } catch (\Throwable) {
                // Observer must not break writers if version service unavailable.
            }
        });
    }

    public function updateTimestamps()
    {
        $wpPostId = $this->relationLoaded('wordpressLink')
            ? $this->wordpressLink?->wp_post_id
            : $this->wordpressLink()->value('wp_post_id');

        if ((int) ($wpPostId ?? 0) > 0) {
            return $this;
        }

        return parent::updateTimestamps();
    }

    public function countsTowardSeoScore(): bool
    {
        $skip = $this->relationLoaded('seoProfile')
            ? $this->seoProfile?->skip_seo_score
            : $this->seoProfile()->value('skip_seo_score');

        return ! (bool) ($skip ?? false);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeCountsTowardSeoScore($query)
    {
        if ($this->articlesColumnExists('skip_seo_score')) {
            return $query->where(function ($sub): void {
                $sub->where('articles.skip_seo_score', false)->orWhereNull('articles.skip_seo_score');
            });
        }

        // Post-drop: join seo_article_profiles for skip filter.
        // Do not force articles.* here when caller already chose columns (GROUP BY / aggregates).
        $query = $query->leftJoin('seo_article_profiles as sap_skip', 'sap_skip.article_id', '=', 'articles.id')
            ->where(function ($sub): void {
                $sub->where('sap_skip.skip_seo_score', false)
                    ->orWhereNull('sap_skip.skip_seo_score');
            });

        if ($query->getQuery()->columns === null) {
            $query->select('articles.*')
                ->addSelect('sap_skip.seo_score as seo_score')
                ->addSelect('sap_skip.skip_seo_score as skip_seo_score');
        }

        return $query;
    }

    /**
     * Articles not in project content archive.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeNotContentArchived($query)
    {
        if ($this->articlesColumnExists('content_archived_at')) {
            return $query->whereNull('articles.content_archived_at');
        }

        return $query->whereNotExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('seo_content_archive_items')
                ->whereColumn('seo_content_archive_items.article_id', 'articles.id');
        });
    }

    private function articlesColumnExists(string $column): bool
    {
        try {
            return Schema::connection($this->getConnectionName() ?: 'omi_seo_ai')
                ->hasColumn('articles', $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Task 6: safe `wp_post_id` lookup — avoids raw `articles.wp_post_id` reads once
     * the addon column is dropped (see Task5 drop migration). Callers should use this
     * instead of `where('wp_post_id', ...)` directly on the Article builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeWhereWpPostId($query, int $wpPostId)
    {
        if ($this->articlesColumnExists('wp_post_id')) {
            return $query->where('articles.wp_post_id', $wpPostId);
        }

        return $query->whereHas('wordpressLink', function ($sub) use ($wpPostId): void {
            $sub->where('wp_post_id', $wpPostId);
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @param  list<int>  $wpPostIds
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeWhereWpPostIdIn($query, array $wpPostIds)
    {
        if ($this->articlesColumnExists('wp_post_id')) {
            return $query->whereIn('articles.wp_post_id', $wpPostIds);
        }

        return $query->whereHas('wordpressLink', function ($sub) use ($wpPostIds): void {
            $sub->whereIn('wp_post_id', $wpPostIds);
        });
    }

    /**
     * Articles that already have a WordPress post id (> 0) — used to scope
     * "synced to WordPress" queries without reading the addon column directly.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeHasWpPostId($query)
    {
        if ($this->articlesColumnExists('wp_post_id')) {
            return $query->whereNotNull('articles.wp_post_id')->where('articles.wp_post_id', '>', 0);
        }

        return $query->whereHas('wordpressLink', function ($sub): void {
            $sub->whereNotNull('wp_post_id')->where('wp_post_id', '>', 0);
        });
    }

    /**
     * Articles due for scheduled publish (published_at <= $before), ordered by published_at.
     * Joins publishing_article_states when the addon column is gone (ORDER BY needs a real join,
     * not a whereHas subquery).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDueScheduledPublish($query, \DateTimeInterface $before)
    {
        if ($this->articlesColumnExists('published_at')) {
            return $query
                ->whereNotNull('articles.published_at')
                ->where('articles.published_at', '<=', $before)
                ->orderBy('articles.published_at');
        }

        return $query
            ->join('publishing_article_states as pas_due', 'pas_due.article_id', '=', 'articles.id')
            ->whereNotNull('pas_due.published_at')
            ->where('pas_due.published_at', '<=', $before)
            ->orderBy('pas_due.published_at')
            ->select('articles.*');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function articleMetas(): HasMany
    {
        return $this->hasMany(ArticleMeta::class, 'article_id');
    }

    public function seoProfile(): HasOne
    {
        return $this->hasOne(\Omnichannel\Addons\Seo\Models\SeoArticleProfile::class, 'article_id');
    }

    public function wordpressLink(): HasOne
    {
        return $this->hasOne(\Omnichannel\Addons\WordPress\Models\WordpressArticleLink::class, 'article_id');
    }

    public function indexHealth(): HasOne
    {
        return $this->hasOne(\Omnichannel\Addons\Seo\Models\SeoArticleIndexHealth::class, 'article_id');
    }

    public function featuredMediaState(): HasOne
    {
        return $this->hasOne(\Omnichannel\Addons\Media\Models\ArticleMediaState::class, 'article_id')
            ->where('role', 'featured');
    }

    public function publishingState(): HasOne
    {
        return $this->hasOne(\Omnichannel\Addons\Publishing\Models\PublishingArticleState::class, 'article_id');
    }

    public function contentArchiveItem(): HasOne
    {
        return $this->hasOne(\Omnichannel\Addons\ContentProjects\Models\SeoContentArchiveItem::class, 'article_id');
    }

    public function linkMaps(): HasMany
    {
        return $this->hasMany(SeoLinkMap::class, 'source_article_id');
    }

    public function headings(): HasMany
    {
        return $this->hasMany(SeoArticleHeading::class, 'article_id')->orderBy('sort_order');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SeoArticleRevision::class, 'article_id')->orderByDesc('created_at');
    }

    public function projectTasks(): HasMany
    {
        // FQCN string — Content must not same-namespace-resolve a missing SeoProjectTask.
        return $this->hasMany(
            \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::class,
            'article_id',
        );
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(SeoFaq::class, 'article_id')->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(SeoArticleReview::class, 'article_id')->orderByDesc('created_at');
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(SeoArticleReview::class, 'article_id')->latestOfMany();
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    public function resolveFaqs(): array
    {
        if ($this->relationLoaded('faqs')) {
            $loaded = $this->faqsToArray($this->faqs);
            // Relation rỗng có thể stale (bundle skip wipe / persist chưa unset) — query lại.
            if ($loaded !== []) {
                return $loaded;
            }
        }

        if (SeoFaq::query()->where('article_id', $this->id)->exists()) {
            return $this->faqsToArray(
                $this->faqs()->orderBy('sort_order')->get()
            );
        }

        $legacy = $this->articleMetas()
            ->where('meta_key', 'seo_article_faqs')
            ->value('meta_value');

        if (! is_string($legacy) || $legacy === '') {
            return [];
        }

        $decoded = json_decode($legacy, true);

        if (! is_array($decoded)) {
            return [];
        }

        $faqs = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            $more = trim((string) ($item['more'] ?? ''));
            $row = ['question' => $question, 'answer' => $answer];
            if ($more !== '') {
                $row['more'] = $more;
            }
            $faqs[] = $row;
        }

        return $faqs;
    }

    /**
     * @param  iterable<int, SeoFaq>  $faqs
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function faqsToArray(iterable $faqs): array
    {
        $result = [];
        foreach ($faqs as $faq) {
            $row = [
                'question' => (string) $faq->question,
                'answer' => (string) $faq->answer,
            ];
            $more = trim((string) ($faq->more ?? ''));
            if ($more !== '') {
                $row['more'] = $more;
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @return array{
     *   internal: array<int, array{href:string,text:string,is_nofollow:bool}>,
     *   external: array<int, array{href:string,text:string,is_nofollow:bool}>
     * }
     */
    public function resolveExtractedLinks(): array
    {
        if ($this->relationLoaded('linkMaps')) {
            return $this->linkMapsToExtractedArray($this->linkMaps);
        }

        if (SeoLinkMap::query()->where('source_article_id', $this->id)->exists()) {
            return $this->linkMapsToExtractedArray(
                $this->linkMaps()->with('targetArticle:id,site_id,title,slug')->orderBy('id')->get(),
            );
        }

        return $this->resolveExtractedLinksFromLegacyMeta();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, SeoLinkMap>|\Illuminate\Support\Collection<int, SeoLinkMap>  $maps
     * @return array{
     *   internal: array<int, array{href:string,text:string,is_nofollow:bool}>,
     *   external: array<int, array{href:string,text:string,is_nofollow:bool}>
     * }
     */
    private function linkMapsToExtractedArray($maps): array
    {
        $internal = [];
        $external = [];
        $wpContent = app(WordPressArticleContentService::class);

        foreach ($maps as $map) {
            if (! $map instanceof SeoLinkMap) {
                continue;
            }

            $href = trim((string) ($map->target_external_url ?? ''));
            if ($href === '' && (int) ($map->target_article_id ?? 0) > 0) {
                $target = $map->relationLoaded('targetArticle')
                    ? $map->targetArticle
                    : $map->targetArticle()->first(['id', 'site_id', 'title', 'slug']);

                if ($target instanceof SeoArticle) {
                    $href = trim($wpContent->resolvePermalink($target));
                }
            }

            if ($href === '') {
                continue;
            }

            $row = [
                'href' => $href,
                'text' => (string) ($map->anchor_text ?? ''),
                'is_nofollow' => false,
            ];

            $linkType = $map->link_type;
            if (
                $linkType === SeoLinkMapType::External
                || $linkType === SeoLinkMapType::WikiTrust
            ) {
                $external[] = $row;

                continue;
            }

            $internal[] = $row;
        }

        return ['internal' => $internal, 'external' => $external];
    }

    /**
     * @return array{internal: array<int, mixed>, external: array<int, mixed>}
     */
    private function resolveExtractedLinksFromLegacyMeta(): array
    {
        $raw = null;

        if ($this->relationLoaded('articleMetas')) {
            $meta = $this->articleMetas->firstWhere('meta_key', 'seo_extracted_links');
            $raw = $meta?->meta_value;
        } else {
            $raw = $this->articleMetas()
                ->where('meta_key', 'seo_extracted_links')
                ->value('meta_value');
        }

        if (! is_string($raw) || trim($raw) === '') {
            return ['internal' => [], 'external' => []];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return ['internal' => [], 'external' => []];
        }

        return [
            'internal' => is_array($decoded['internal'] ?? null) ? $decoded['internal'] : [],
            'external' => is_array($decoded['external'] ?? null) ? $decoded['external'] : [],
        ];
    }

    public function getInternalLinkCountAttribute(): int
    {
        if (array_key_exists('internal_link_count', $this->attributes) && $this->attributes['internal_link_count'] !== null) {
            return (int) $this->attributes['internal_link_count'];
        }

        return count($this->resolveExtractedLinks()['internal']);
    }

    public function getExternalLinkCountAttribute(): int
    {
        if (array_key_exists('external_link_count', $this->attributes) && $this->attributes['external_link_count'] !== null) {
            return (int) $this->attributes['external_link_count'];
        }

        return count($this->resolveExtractedLinks()['external']);
    }
}
