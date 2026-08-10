<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Models;

use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Local pending Product Review. WordPress is source of truth after status=reviewed.
 */
class ArticleProductReview extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'article_product_reviews';

    protected $guarded = [];

    protected $casts = [
        'status' => ArticleProductReviewStatus::class,
        'rating' => 'integer',
        'publish_attempts' => 'integer',
        'selected_delay_seconds' => 'integer',
        'configured_max_delay_minutes' => 'integer',
        'review_date' => 'datetime',
        'published_at' => 'datetime',
        'synced_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'publishing_started_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'wp_post_id' => 'integer',
        'wp_comment_id' => 'integer',
        'article_id' => 'integer',
        'site_id' => 'integer',
        'connection_id' => 'integer',
        'publish_execution_id' => 'integer',
        'retry_count' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toEditorArray(): array
    {
        $row = [
            'id' => (int) $this->id,
            'author' => (string) $this->author_name,
            'content' => (string) $this->content,
            'date' => $this->review_date?->format('Y-m-d H:i:s') ?? $this->created_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            'status' => $this->status instanceof ArticleProductReviewStatus
                ? $this->status->value
                : (string) $this->status,
            'wp_comment_id' => $this->wp_comment_id,
            'published_at' => $this->published_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'selected_delay_seconds' => $this->selected_delay_seconds,
            'configured_max_delay_minutes' => $this->configured_max_delay_minutes,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'last_error_message' => $this->last_error_message,
            'last_error_code' => $this->last_error_code,
            'pending_local' => ! ($this->status instanceof ArticleProductReviewStatus
                ? $this->status->isReviewed()
                : false),
        ];

        if ($this->author_email !== null && $this->author_email !== '') {
            $row['author_email'] = (string) $this->author_email;
        }

        if ($this->rating !== null) {
            $row['rating'] = (int) $this->rating;
        }

        return $row;
    }
}
