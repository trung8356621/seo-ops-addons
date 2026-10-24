<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

final class SaRpaExecutionReport extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;
    protected $table = 'sa_rpa_execution_reports';
    protected $guarded = [];
    protected function casts(): array { return ['step_version' => 'integer', 'occurred_at' => 'datetime', 'details' => 'array']; }
}
