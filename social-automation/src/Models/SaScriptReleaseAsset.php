<?php
declare(strict_types=1);
namespace Omnichannel\Addons\SocialAutomation\Models;
use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
final class SaScriptReleaseAsset extends Model { protected $connection = SocialAutomationServiceConfig::CONNECTION; protected $table = 'sa_script_release_assets'; protected $guarded = []; protected function casts(): array { return ['size_bytes' => 'integer']; } }
