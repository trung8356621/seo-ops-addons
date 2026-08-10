<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordReviewReason extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $fillable = [
        'workspace_id',
        'name',
        'default_severity',
        'description',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'workspace_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_by' => 'integer',
    ];

    public function createdByUser(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'created_by');
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'review_reason_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(KeywordReviewHistory::class, 'reason_id');
    }

    public function isUsed(): bool
    {
        return $this->histories()->exists();
    }

    public function defaultSeverityEnum(): KeywordReviewStatus
    {
        return KeywordReviewStatus::tryFrom((string) $this->default_severity) ?? KeywordReviewStatus::Warning;
    }
}
