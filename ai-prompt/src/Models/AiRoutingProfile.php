<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRoutingProfile extends Model
{
    protected $table = 'ai_routing_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'required_capabilities' => 'array',
            'settings' => 'array',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(AiRoutingTarget::class, 'profile_id');
    }
}
