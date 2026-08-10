<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptResult extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $guarded = [];

    protected $casts = [
        'input_snapshot' => 'array',
        'token_usage'    => 'array',
        'started_at'     => 'datetime',
        'finished_at'    => 'datetime',
    ];

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
