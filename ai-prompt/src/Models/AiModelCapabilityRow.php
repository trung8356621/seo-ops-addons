<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelCapabilityRow extends Model
{
    protected $table = 'ai_model_capabilities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function seoAiModel(): BelongsTo
    {
        return $this->belongsTo(SeoAiModel::class, 'seo_ai_model_id');
    }
}
