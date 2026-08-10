<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookSchemaGuard;
use Illuminate\Console\Command;

/**
 * Emergency: tắt toàn bộ automation rule — kỳ vọng zero automatic side effect.
 */
final class AutomationDisableAllRulesCommand extends Command
{
    protected $signature = 'automation:disable-all-rules {--force : Skip confirmation}';

    protected $description = 'Disable every automation rule (emergency kill-switch).';

    public function handle(): int
    {
        $missing = BusinessHookSchemaGuard::missingTables();
        if ($missing !== []) {
            $this->error('Business Hook tables missing: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $enabled = (int) AutomationRule::query()->where('is_enabled', true)->count();
        if ($enabled === 0) {
            $this->info('No enabled automation rules.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Disable {$enabled} enabled rule(s)?")) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $updated = AutomationRule::query()->where('is_enabled', true)->update(['is_enabled' => false]);
        $this->info("Disabled {$updated} automation rule(s).");

        return self::SUCCESS;
    }
}
