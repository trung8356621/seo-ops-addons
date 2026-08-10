<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRunMode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationInputMapper;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookSchemaGuard;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AutomationDiagnoseCommand extends Command
{
    private const STALE_PROCESSING_SECONDS = 900;

    /** @var list<string> */
    private const UNSAFE_SYNC_MODE_ACTIONS = [
        AutomationActionCode::WordpressArticleSync->value,
        AutomationActionCode::WebhookSend->value,
    ];

    protected $signature = 'automation:diagnose {--strict : Exit non-zero on any critical issue}';

    protected $description = 'Diagnose automation rules, registry wiring, and execution health.';

    public function handle(
        BusinessEventRegistry $eventRegistry,
        AutomationActionRegistry $actionRegistry,
        AutomationConditionEngine $conditionEngine,
        AutomationInputMapper $inputMapper,
    ): int {
        $strict = (bool) $this->option('strict');
        $issues = [];
        $hasCritical = false;

        $missingTables = BusinessHookSchemaGuard::missingTables();
        if ($missingTables !== []) {
            $this->pushIssue(
                $issues,
                $hasCritical,
                'critical',
                'MISSING_TABLES',
                'Business Hook tables missing: '.implode(', ', $missingTables).'. Run: '.BusinessHookSchemaGuard::migrateHint(),
            );
        }

        if ($missingTables === []) {
            $missingV2 = BusinessHookSchemaGuard::missingV2Tables();
            if ($missingV2 !== []) {
                $this->pushIssue(
                    $issues,
                    $hasCritical,
                    'warning',
                    'MISSING_V2_TABLES',
                    'Automation V2 tables missing: '.implode(', ', $missingV2).'. Run: '.BusinessHookSchemaGuard::migrateV2Hint(),
                );
            }

            $missingV3 = BusinessHookSchemaGuard::missingV3Tables();
            $missingV3Cols = BusinessHookSchemaGuard::missingV3Columns();
            if ($missingV3 !== [] || $missingV3Cols !== []) {
                $this->pushIssue(
                    $issues,
                    $hasCritical,
                    'critical',
                    'MISSING_V3_SCHEMA',
                    'Automation V3 schema missing: '.implode(', ', array_merge($missingV3, $missingV3Cols)).'. Run: '.BusinessHookSchemaGuard::migrateV3Hint(),
                );
            }

            $this->collectRuleIssues($issues, $hasCritical, $strict, $eventRegistry, $actionRegistry, $conditionEngine, $inputMapper);
            $this->collectExecutionIssues($issues, $hasCritical, $strict);
            if ($missingV3 === [] && $missingV3Cols === []) {
                $this->collectVersionIssues($issues, $hasCritical);
            }
        }

        if ($issues === []) {
            $this->info('No issues detected.');
        } else {
            $this->table(
                ['Severity', 'Code', 'Detail'],
                array_map(static fn (array $issue): array => [
                    $issue['severity'],
                    $issue['code'],
                    $issue['detail'],
                ], $issues),
            );
        }

        if ($hasCritical) {
            $this->error('Critical issues found.');
            if ($strict) {
                $this->line('--strict: exit non-zero on critical issues (warnings alone do not fail).');
            }

            return self::FAILURE;
        }

        $this->info('No critical issues.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{severity: string, code: string, detail: string}>  $issues
     */
    private function collectRuleIssues(
        array &$issues,
        bool &$hasCritical,
        bool $strict,
        BusinessEventRegistry $eventRegistry,
        AutomationActionRegistry $actionRegistry,
        AutomationConditionEngine $conditionEngine,
        AutomationInputMapper $inputMapper,
    ): void {
        $rules = AutomationRule::query()->with('actions')->get();

        foreach ($rules as $rule) {
            if (! $rule instanceof AutomationRule) {
                continue;
            }

            $ruleEnabled = (bool) $rule->is_enabled;

            if ($ruleEnabled && ! $eventRegistry->has($rule->event_name)) {
                $this->pushIssue(
                    $issues,
                    $hasCritical,
                    'critical',
                    'UNREGISTERED_EVENT',
                    "Enabled rule [{$rule->code}] references unregistered event [{$rule->event_name}].",
                );
            }

            $enabledActionCount = 0;
            $positions = [];

            foreach ($rule->actions as $action) {
                if (! $action instanceof AutomationRuleAction) {
                    continue;
                }

                if ((bool) $action->is_enabled) {
                    $enabledActionCount++;
                }

                if ($ruleEnabled && ! $actionRegistry->has($action->action_code)) {
                    $this->pushIssue(
                        $issues,
                        $hasCritical,
                        'critical',
                        'UNREGISTERED_ACTION',
                        "Enabled rule [{$rule->code}] action position {$action->position} uses unregistered [{$action->action_code}].",
                    );
                }

                $pos = (int) $action->position;
                if (isset($positions[$pos])) {
                    $severity = ($ruleEnabled && ($positions[$pos]['enabled'] || $action->is_enabled))
                        ? 'critical'
                        : 'warning';
                    $this->pushIssue(
                        $issues,
                        $hasCritical,
                        $severity,
                        'DUPLICATE_ACTION_POSITION',
                        "Rule [{$rule->code}] has duplicate action position [{$pos}].",
                    );
                } else {
                    $positions[$pos] = ['enabled' => (bool) $action->is_enabled];
                }

                if ($rule->run_mode === AutomationRunMode::Sync->value
                    && (bool) $action->is_enabled
                    && in_array($action->action_code, self::UNSAFE_SYNC_MODE_ACTIONS, true)) {
                    $this->pushIssue(
                        $issues,
                        $hasCritical,
                        $strict ? 'critical' : 'warning',
                        'SYNC_MODE_EXTERNAL_ACTION',
                        "Rule [{$rule->code}] run_mode=sync includes external action [{$action->action_code}].",
                    );
                }

                $this->collectMappingIssues($issues, $hasCritical, $rule, $action, $inputMapper);
            }

            $conditionErrors = $conditionEngine->validate($rule->conditions);
            foreach ($conditionErrors as $error) {
                $this->pushIssue(
                    $issues,
                    $hasCritical,
                    $ruleEnabled ? 'critical' : 'warning',
                    'INVALID_CONDITION',
                    "Rule [{$rule->code}]: {$error}",
                );
            }

            if ($ruleEnabled && $enabledActionCount === 0) {
                $this->pushIssue(
                    $issues,
                    $hasCritical,
                    $strict ? 'critical' : 'warning',
                    'ZERO_ENABLED_ACTIONS',
                    "Enabled rule [{$rule->code}] has zero enabled actions.",
                );
            }
        }

        $duplicateCodes = AutomationRule::query()
            ->select('code', DB::raw('COUNT(*) as c'))
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateCodes as $row) {
            $this->pushIssue(
                $issues,
                $hasCritical,
                'critical',
                'DUPLICATE_RULE_CODE',
                "Duplicate rule code [{$row->code}] count={$row->c}.",
            );
        }
    }

    /**
     * @param  list<array{severity: string, code: string, detail: string}>  $issues
     */
    private function collectMappingIssues(
        array &$issues,
        bool &$hasCritical,
        AutomationRule $rule,
        AutomationRuleAction $action,
        AutomationInputMapper $inputMapper,
    ): void {
        $mapping = $action->input_mapping ?? [];
        if (! is_array($mapping)) {
            $severity = (bool) $rule->is_enabled ? 'critical' : 'warning';
            $this->pushIssue(
                $issues,
                $hasCritical,
                $severity,
                'INVALID_INPUT_MAPPING',
                "Rule [{$rule->code}] action [{$action->action_code}] input_mapping is not an array.",
            );

            return;
        }

        $sources = [
            'event' => ['site_id' => 1],
            'payload' => ['title' => 'sample'],
            'context' => [],
            'subject' => ['id' => 1],
            'previous' => [],
        ];

        foreach ($this->extractPlaceholderPaths($mapping) as $path) {
            try {
                $inputMapper->resolvePath($path, $sources);
            } catch (AutomationException $e) {
                $severity = (bool) $rule->is_enabled ? 'critical' : 'warning';
                $this->pushIssue(
                    $issues,
                    $hasCritical,
                    $severity,
                    'INVALID_INPUT_MAPPING',
                    "Rule [{$rule->code}] action [{$action->action_code}] path [{$path}]: {$e->getMessage()}",
                );
            }
        }
    }

    /**
     * @param  list<array{severity: string, code: string, detail: string}>  $issues
     */
    private function collectExecutionIssues(array &$issues, bool &$hasCritical, bool $strict): void
    {
        $staleProcessing = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Processing->value)
            ->where(function ($q): void {
                $q->whereNull('started_at')
                    ->orWhere('started_at', '<', now()->subSeconds(self::STALE_PROCESSING_SECONDS));
            })
            ->count();

        if ($staleProcessing > 0) {
            $this->pushIssue(
                $issues,
                $hasCritical,
                $strict ? 'critical' : 'warning',
                'STALE_PROCESSING',
                "{$staleProcessing} execution(s) stuck in processing > ".(self::STALE_PROCESSING_SECONDS / 60).' min.',
            );
        }

        $pendingCount = (int) AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Pending->value)
            ->count();

        if ($pendingCount > 0) {
            $this->pushIssue(
                $issues,
                $hasCritical,
                'warning',
                'PENDING_EXECUTIONS',
                "{$pendingCount} pending execution(s).",
            );
        }

        $failed24h = (int) AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed->value)
            ->where('finished_at', '>=', now()->subDay())
            ->count();

        if ($failed24h > 0) {
            $this->pushIssue(
                $issues,
                $hasCritical,
                'warning',
                'FAILED_24H',
                "{$failed24h} failed execution(s) in last 24h.",
            );
        }

        $depthLoop24h = (int) AutomationExecution::query()
            ->whereIn('error_code', [
                BusinessHookErrorCode::MaxDepthExceeded->value,
                BusinessHookErrorCode::LoopDetected->value,
            ])
            ->where('finished_at', '>=', now()->subDay())
            ->count();

        if ($depthLoop24h > 0) {
            $this->pushIssue(
                $issues,
                $hasCritical,
                'warning',
                'DEPTH_LOOP_24H',
                "{$depthLoop24h} execution(s) with max-depth/loop errors in last 24h.",
            );
        }
    }

    /**
     * @param  list<array{severity: string, code: string, detail: string}>  $issues
     */
    private function collectVersionIssues(array &$issues, bool &$hasCritical): void
    {
        $enabledGraphMissingPublished = AutomationRule::query()
            ->where('is_enabled', true)
            ->where('workflow_mode', 'graph')
            ->whereNull('published_version_id')
            ->count();

        if ($enabledGraphMissingPublished > 0) {
            $this->pushIssue(
                $issues,
                $hasCritical,
                'critical',
                'ENABLED_GRAPH_WITHOUT_PUBLISHED_VERSION',
                "{$enabledGraphMissingPublished} enabled graph rule(s) lack published_version_id. Run automation:migrate-rule-versions --apply or publish.",
            );
        }

        $draftBoundExecutions = AutomationExecution::query()
            ->whereNull('automation_rule_version_id')
            ->whereHas('rule', static fn ($q) => $q->where('workflow_mode', 'graph'))
            ->whereIn('status', [
                AutomationExecutionStatus::Pending->value,
                AutomationExecutionStatus::Processing->value,
            ])
            ->count();

        if ($draftBoundExecutions > 0) {
            $this->pushIssue(
                $issues,
                $hasCritical,
                'critical',
                'GRAPH_EXECUTION_WITHOUT_VERSION',
                "{$draftBoundExecutions} active graph execution(s) missing automation_rule_version_id (would risk draft).",
            );
        }
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function extractPlaceholderPaths(mixed $value): array
    {
        $paths = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                foreach ($this->extractPlaceholderPaths($item) as $path) {
                    $paths[] = $path;
                }
            }

            return array_values(array_unique($paths));
        }

        if (! is_string($value)) {
            return [];
        }

        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $value, $matches) < 1) {
            return [];
        }

        foreach ($matches[1] as $path) {
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<array{severity: string, code: string, detail: string}>  $issues
     */
    private function pushIssue(array &$issues, bool &$hasCritical, string $severity, string $code, string $detail): void
    {
        if ($severity === 'critical') {
            $hasCritical = true;
        }

        $issues[] = [
            'severity' => $severity,
            'code' => $code,
            'detail' => $detail,
        ];
    }
}
