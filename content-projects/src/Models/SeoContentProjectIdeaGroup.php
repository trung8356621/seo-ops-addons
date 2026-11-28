<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;

class SeoContentProjectIdeaGroup extends Model
{
    public const SYSTEM_GROUP_NAME = 'Chung';

    public const MAX_GROUPS_PER_OWNER = 5;

    public const MAX_MANUAL_GROUPS = 4;

    public const MAX_NAME_LENGTH = 120;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_idea_groups';

    protected $guarded = [];

    protected $casts = [
        'owner_user_id' => 'integer',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];
}
