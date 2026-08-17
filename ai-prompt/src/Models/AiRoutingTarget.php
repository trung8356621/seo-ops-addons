<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use App\Models\ApiConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRoutingTarget extends Model
{
    protected $table = 'ai_routing_targets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'enabled' => 'boolean',
            'options' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AiRoutingProfile::class, 'profile_id');
    }

    public function apiConnection(): BelongsTo
    {
        return $this->belongsTo(ApiConnection::class, 'api_connection_id');
    }

    public function seoAiModel(): BelongsTo
    {
        return $this->belongsTo(SeoAiModel::class, 'seo_ai_model_id');
    }
}
