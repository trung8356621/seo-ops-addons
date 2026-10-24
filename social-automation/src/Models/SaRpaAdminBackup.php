<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

final class SaRpaAdminBackup extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;
    protected $table = 'sa_rpa_admin_backups';
    protected $guarded = [];
    protected function casts(): array { return ['draft_revision' => 'integer', 'size_bytes' => 'integer', 'summary' => 'array', 'backed_up_at' => 'datetime']; }
}
