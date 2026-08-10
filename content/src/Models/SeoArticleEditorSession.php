<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Models;

use Omnichannel\Addons\Content\Enums\ArticleEditorSessionStatus;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoArticleEditorSession extends Model
{
    use BelongsToOnDefaultConnection;
    use HasUuids;

    protected $connection = 'omi_seo_ai';

    protected $table = 'article_editor_sessions';

    protected $guarded = [];

    protected $casts = [
        'status' => ArticleEditorSessionStatus::class,
        'acquired_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'revoked_at' => 'datetime',
        'site_id' => 'integer',
        'user_id' => 'integer',
        'article_id' => 'integer',
        'takeover_by_user_id' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'user_id');
    }

    public function takeoverByUser(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'takeover_by_user_id');
    }

    public function isActiveLock(?\DateTimeInterface $now = null): bool
    {
        if ($this->status !== ArticleEditorSessionStatus::Active) {
            return false;
        }

        $expiresAt = $this->expires_at;
        if ($expiresAt === null) {
            return false;
        }

        $nowTs = ($now ?? now())->getTimestamp();

        return $expiresAt->getTimestamp() > $nowTs;
    }
}
