<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoProjectTaskEvent extends Model
{
    use BelongsToOnDefaultConnection;

    public const UPDATED_AT = null;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_task_events';

    protected $guarded = [];

    protected $casts = [
        'task_id' => 'integer',
        'run_id' => 'integer',
        'created_by' => 'integer',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'task_id');
    }

    public function taskIncludingDeleted(): BelongsTo
    {
        return $this->belongsTo(SeoProjectTask::class, 'task_id')->withTrashed();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoProjectRun::class, 'run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'created_by');
    }
}
