<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Seed\AutomationDefaultRulesSeeder;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookSchemaGuard;
use Illuminate\Console\Command;

final class AutomationSeedRulesCommand extends Command
{
    protected $signature = 'automation:seed-rules';

    protected $description = 'Seed default automation rules via AutomationDefaultRulesSeeder.';

    public function handle(AutomationDefaultRulesSeeder $seeder): int
    {
        $missing = BusinessHookSchemaGuard::missingTables();
        if ($missing !== []) {
            $this->error('Business Hook tables missing: '.implode(', ', $missing));
            $this->line('Run SEO migrations first:');
            $this->line('  '.BusinessHookSchemaGuard::migrateHint());
            $this->line('Or Admin → SEO Database Connections → Run migrations.');

            return self::FAILURE;
        }

        $seeder->seed();
        $this->info('Default automation rules seeded (missing codes only).');

        $publishRule = AutomationRule::query()
            ->where('code', 'dispatch-publish-request')
            ->first();

        if (! $publishRule instanceof AutomationRule) {
            $this->error('CRITICAL: rule dispatch-publish-request missing after seed.');
            $this->line('Publishing Queue emits article.publish_requested then SKIPPED_NO_RULE — WordPress will not sync.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'dispatch-publish-request: enabled=%s published_version_id=%s event=%s',
            $publishRule->is_enabled ? 'yes' : 'no',
            $publishRule->published_version_id !== null ? (string) $publishRule->published_version_id : 'null',
            BusinessEventName::ArticlePublishRequested->value,
        ));

        if (! (bool) $publishRule->is_enabled || $publishRule->published_version_id === null) {
            $this->warn('Rule exists but not enabled/published — enable on Automation UI or re-publish version.');
        }

        $this->line('After seed: run queue worker so ExecuteAutomationRuleJob can run wordpress.article.sync.');

        return self::SUCCESS;
    }
}
