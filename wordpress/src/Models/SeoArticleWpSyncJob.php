<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Models;

use Omnichannel\Addons\WordPress\Enums\WpSyncJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lease-based WordPress sync job (omi_seo_ai). Source of truth cho queue state.
 */
final class SeoArticleWpSyncJob extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_wp_sync_jobs';

    protected $guarded = [];

    protected $casts = [
        'status' => WpSyncJobStatus::class,
        'attempts' => 'integer',
        'settings' => 'array',
        'audit_meta' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'locked_until' => 'datetime',
        'finished_at' => 'datetime',
        'wp_post_id' => 'integer',
        'article_id' => 'integer',
        'site_id' => 'integer',
        'initiated_by' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function isActive(): bool
    {
        return $this->status instanceof WpSyncJobStatus && $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status instanceof WpSyncJobStatus && $this->status->isTerminal();
    }

    public static function makeIdempotencyKey(int $siteId, int $articleId): string
    {
        return 'wordpress_sync:'.$siteId.':'.$articleId;
    }
}
