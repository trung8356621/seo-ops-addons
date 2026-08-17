<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use App\Models\ApiConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAiModel extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_EXHAUSTED = 'exhausted';

    protected $table = 'seo_ai_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'capabilities' => 'array',
            'is_hidden' => 'boolean',
        ];
    }

    public function apiConnection(): BelongsTo
    {
        return $this->belongsTo(ApiConnection::class, 'api_connection_id');
    }
}
