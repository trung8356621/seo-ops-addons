<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoPendingInternalLink extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_pending_internal_links';

    protected $fillable = [
        'site_id',
        'source_article_id',
        'keyword_id',
        'anchor_phrase',
        'placeholder_hash',
        'status',
        'resolved_target_url',
        'resolved_target_article_id',
        'resolved_at',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'source_article_id' => 'integer',
        'keyword_id' => 'integer',
        'resolved_target_article_id' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function sourceArticle(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'source_article_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    public function resolvedTargetArticle(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'resolved_target_article_id');
    }

    public function placeholderHref(): string
    {
        return '#'.trim((string) $this->placeholder_hash);
    }
}
