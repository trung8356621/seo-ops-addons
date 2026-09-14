<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

class SeedingCommentPromptSetting extends Model
{
    protected $connection = SeedingServiceConfig::CONNECTION;

    protected $table = 'seeding_comment_prompt_settings';

    /** @var list<string> */
    protected $fillable = [
        'prompt_body',
    ];
}
