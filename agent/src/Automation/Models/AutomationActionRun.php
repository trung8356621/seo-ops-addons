<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Models;

use App\Support\Automation\AutomationModel;

/**
 * Execution log cho Business Action runner.
 *
 * @property string $execution_id
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property string $action_key
 * @property string|null $origin
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property int|null $team_id
 * @property int|null $site_id
 * @property string $status
 * @property int $attempt
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $input_json
 * @property array<string, mixed>|null $output_json
 * @property array<int, string>|null $warning_json
 * @property array<string, mixed>|null $error_json
 */
final class AutomationActionRun extends AutomationModel
{
    protected $table = 'automation_action_runs';

    protected $guarded = [];

    protected $casts = [
        'entity_id' => 'integer',
        'team_id' => 'integer',
        'site_id' => 'integer',
        'attempt' => 'integer',
        'input_json' => 'array',
        'output_json' => 'array',
        'warning_json' => 'array',
        'error_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
