<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTask extends Model
{
    use UsesCoreDatabaseConnection;

    protected $table = 'seo_tasks';

    protected $guarded = [];

    protected $casts = [
        'flow_data' => 'json',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
