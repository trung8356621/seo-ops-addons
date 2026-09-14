<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

/**
 * Ring-buffer slot row (1–20). Newest-first order uses sequence, not slot id.
 */
class SeedingCommentGenerateLog extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'slot';

    protected $keyType = 'int';

    protected $connection = SeedingServiceConfig::CONNECTION;

    protected $table = 'seeding_comment_generate_logs';

    /** @var list<string> */
    protected $fillable = [
        'slot',
        'sequence',
        'topic_id',
        'social',
        'quantity',
        'mcp_context',
        'final_prompt',
        'ai_output',
        'provider',
        'model',
        'status',
        'error_message',
        'generated_at',
    ];

    protected $casts = [
        'slot' => 'integer',
        'sequence' => 'integer',
        'topic_id' => 'integer',
        'quantity' => 'integer',
        'generated_at' => 'datetime',
    ];
}
