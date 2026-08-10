<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Illuminate\Support\Facades\Log;

final class AutomationRuleVersionMigrationService
{
    public function __construct(
        private readonly AutomationVersionService $versionService,
    ) {}

    /**
     * @return array{total: int, migrated: int, skipped: int, errors: list<string>}
     */
    public function migrate(bool $apply = false): array
    {
        $stats = ['total' => 0, 'migrated' => 0, 'skipped' => 0, 'errors' => []];

        $rules = AutomationRule::query()
            ->whereNull('published_version_id')
            ->with(['nodes', 'edges', 'actions'])
            ->orderBy('id')
            ->get();

        $stats['total'] = $rules->count();

        foreach ($rules as $rule) {
            if (! $rule instanceof AutomationRule) {
                continue;
            }

            if ($rule->published_version_id !== null) {
                $stats['skipped']++;
                continue;
            }

            if (! $apply) {
                $stats['migrated']++;
                continue;
            }

            try {
                $this->versionService->publish($rule);
                $stats['migrated']++;
            } catch (\Throwable $e) {
                $stats['errors'][] = "rule {$rule->code}: {$e->getMessage()}";
                Log::warning('automation.migrate_rule_versions.failed', [
                    'rule_id' => $rule->id,
                    'code' => $rule->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }
}
