<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Illuminate\Database\Eloquent\Model;

class SeoContentProjectIdeaWorkspace extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_idea_workspaces';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'note_items' => 'array',
        'revision' => 'integer',
        'updated_by' => 'integer',
    ];
}
