<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Current semantic keyword-grouping execution state for one site.
 */
final class SeoKeywordGroupSemanticState extends Model
{
    public const PHASE_PENDING = 'pending';

    public const PHASE_RUNNING = 'running';

    public const PHASE_SUCCEEDED = 'succeeded';

    public const PHASE_FAILED = 'failed';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_group_semantic_states';

    protected $fillable = [
        'site_id',
        'phase',
        'ready_at',
        'invalidated_at',
        'last_error',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'ready_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];
}
