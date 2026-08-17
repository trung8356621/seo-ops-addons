<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use Illuminate\Database\Eloquent\Model;

class AiProviderTemplate extends Model
{
    protected $connection = 'mysql';

    protected $table = 'ai_provider_templates';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_builtin' => 'boolean',
            'enabled' => 'boolean',
            'revision' => 'integer',
        ];
    }
}
