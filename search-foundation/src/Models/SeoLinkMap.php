<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoLinkMap extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_link_maps';

    protected $fillable = [
        'keyword_id',
        'source_article_id',
        'target_article_id',
        'target_external_url',
        'anchor_text',
        'context_before',
        'context_after',
        'link_type',
        'status',
        'last_http_status',
        'last_audited_at',
    ];

    protected $casts = [
        'keyword_id' => 'integer',
        'source_article_id' => 'integer',
        'target_article_id' => 'integer',
        'link_type' => SeoLinkMapType::class,
        'status' => SeoLinkMapStatus::class,
        'last_http_status' => 'integer',
        'last_audited_at' => 'datetime',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    public function sourceArticle(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'source_article_id');
    }

    public function targetArticle(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'target_article_id');
    }
}
