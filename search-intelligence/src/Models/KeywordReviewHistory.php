<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordReviewHistory extends Model
{
    use BelongsToOnDefaultConnection;

    public $timestamps = false;

    protected $connection = 'omi_seo_ai';

    protected $fillable = [
        'keyword_id',
        'article_id',
        'from_status',
        'to_status',
        'reason_id',
        'severity',
        'note',
        'source',
        'reviewed_by',
        'created_at',
    ];

    protected $casts = [
        'keyword_id' => 'integer',
        'article_id' => 'integer',
        'reason_id' => 'integer',
        'reviewed_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(KeywordReviewReason::class, 'reason_id');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'reviewed_by');
    }
}
