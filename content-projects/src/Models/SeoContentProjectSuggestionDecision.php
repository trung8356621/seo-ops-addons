<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoContentProjectSuggestionDecision extends Model
{
    public const SOURCE_SEO_AUDIT = 'seo_audit';

    public const SOURCE_AI_NEW_CONTENT = 'ai_new_content';

    public const DECISION_DISMISSED = 'dismissed';

    public const DECISION_ACCEPTED = 'accepted';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_suggestion_decisions';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'site_id' => 'integer',
        'article_id' => 'integer',
        'created_by' => 'integer',
        'meta' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }

    public static function articleSourceKey(int $articleId): string
    {
        return 'article:'.$articleId;
    }

    public static function fingerprintSourceKey(string $fingerprint): string
    {
        return 'fp:'.$fingerprint;
    }
}
