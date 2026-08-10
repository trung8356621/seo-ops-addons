<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\V1;

use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentEvaluationDataset;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceVersion;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\BuiltinAgentEvaluationDatasetInstaller;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackCache;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Non-destructive Agent Workspace v1 readiness doctor.
 */
final class AgentV1ReadinessService
{
    public function __construct(
        private readonly AgentCapabilityCoverageAuditService $audit,
        private readonly CanonicalCapabilityRegistry $capabilities,
        private readonly AgentSkillRegistry $skills,
        private readonly ?AgentPackRegistry $packs = null,
        private readonly ?AgentPackCache $packCache = null,
        private readonly ?BuiltinAgentEvaluationDatasetInstaller $datasets = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(bool $fixSafe = false, bool $skipProvider = true): array
    {
        $checks = [];
        $checks[] = $this->check('version_metadata', 'PASS', AgentWorkspaceVersion::VERSION, AgentWorkspaceVersion::snapshot());
        $checks[] = $this->checkMigrations();
        $checks[] = $this->checkPageClass();
        $checks[] = $this->checkRegistryBindings();
        $checks[] = $this->checkP0Capabilities();
        $checks[] = $this->checkP0Skills();
        $checks[] = $this->checkSlashIntegrity();
        $checks[] = $this->checkConfirmationPolicies();
        $checks[] = $this->checkInternalNotInCatalog();
        $checks[] = $this->checkBuiltinDatasets($fixSafe);
        $checks[] = $this->checkPacks($fixSafe);
        $checks[] = $this->checkCoverage();
        $checks[] = $this->checkObservabilityStorage();
        $checks[] = $this->checkAutomationCommand();
        if (! $skipProvider) {
            $checks[] = $this->check('planning_provider', 'WARN', 'Provider probe skipped in default doctor; use live UI.', []);
        } else {
            $checks[] = $this->check('planning_provider', 'WARN', 'Skipped (--skip-provider / default).', []);
        }

        $fail = 0;
        $warn = 0;
        foreach ($checks as $c) {
            if ($c['status'] === 'FAIL') {
                $fail++;
            } elseif ($c['status'] === 'WARN') {
                $warn++;
            }
        }

        $overall = $fail > 0 ? 'not_ready' : ($warn > 0 ? 'ready_with_warnings' : 'ready');

        return [
            'ok' => $fail === 0,
            'overall' => $overall,
            'version' => AgentWorkspaceVersion::snapshot(),
            'generated_at' => gmdate(DATE_ATOM),
            'fix_safe_applied' => $fixSafe,
            'counts' => ['fail' => $fail, 'warn' => $warn, 'pass' => count($checks) - $fail - $warn],
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkMigrations(): array
    {
        $tables = [
            'seo_agent_conversations',
            'seo_agent_packs',
            'seo_agent_evaluation_datasets',
            'seo_agent_traces',
        ];
        $missing = [];
        try {
            $schema = Schema::connection('omi_seo_ai');
            foreach ($tables as $t) {
                if (! $schema->hasTable($t)) {
                    $missing[] = $t;
                }
            }
        } catch (Throwable $e) {
            return $this->check('migrations', 'WARN', 'Cannot inspect omi_seo_ai: '.$e->getMessage(), []);
        }

        return $missing === []
            ? $this->check('migrations', 'PASS', 'Required Agent tables present.', ['tables' => $tables])
            : $this->check('migrations', 'FAIL', 'Missing tables.', ['missing' => $missing]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPageClass(): array
    {
        return class_exists(AgentWorkspacePage::class)
            ? $this->check('agent_workspace_page', 'PASS', 'AgentWorkspacePage resolvable.', [])
            : $this->check('agent_workspace_page', 'FAIL', 'AgentWorkspacePage missing.', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRegistryBindings(): array
    {
        try {
            $n = count($this->capabilities->all());
            $s = count($this->skills->all(true));

            return $this->check('registry_bindings', 'PASS', "capabilities={$n} skills={$s}", compact('n', 's'));
        } catch (Throwable $e) {
            return $this->check('registry_bindings', 'FAIL', $e->getMessage(), []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkP0Capabilities(): array
    {
        $required = [];
        foreach (AgentCapabilityInventory::rows() as $row) {
            if (($row['priority'] ?? '') !== 'P0' || ($row['operation_type'] ?? '') === 'internal') {
                continue;
            }
            if (is_string($row['capability_key'] ?? null)) {
                $required[] = $row['capability_key'];
            }
        }
        $missing = [];
        foreach (array_unique($required) as $cap) {
            if (str_starts_with($cap, 'agent.')) {
                continue;
            }
            if ($this->capabilities->get($cap) === null
                && ! in_array($cap, \Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway::READ_CAPABILITIES, true)) {
                $missing[] = $cap;
            }
        }

        return $missing === []
            ? $this->check('p0_capabilities', 'PASS', 'P0 capabilities present.', [])
            : $this->check('p0_capabilities', 'FAIL', 'Missing P0 capabilities.', ['missing' => $missing]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkP0Skills(): array
    {
        $missing = [];
        foreach (AgentCapabilityInventory::rows() as $row) {
            if (($row['priority'] ?? '') !== 'P0' || ($row['operation_type'] ?? '') === 'internal') {
                continue;
            }
            $skillKey = $row['skill_key'] ?? null;
            if (! is_string($skillKey) || $skillKey === '') {
                continue;
            }
            if ($this->skills->get($skillKey) === null) {
                $missing[] = $skillKey;
            }
        }

        return $missing === []
            ? $this->check('p0_skills', 'PASS', 'P0 skills present.', [])
            : $this->check('p0_skills', 'FAIL', 'Missing P0 skills.', ['missing' => $missing]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSlashIntegrity(): array
    {
        try {
            $this->skills->all(true);

            return $this->check('slash_commands', 'PASS', 'No slash conflicts at registry boot.', []);
        } catch (Throwable $e) {
            return $this->check('slash_commands', 'FAIL', $e->getMessage(), []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkConfirmationPolicies(): array
    {
        $bad = [];
        foreach ($this->skills->all(true) as $skill) {
            if (! in_array($skill->confirmationPolicy, ['none', 'preview', 'confirm', 'destructive'], true)) {
                $bad[] = $skill->key;
            }
            if (str_contains($skill->key, 'archive') && $this->rank($skill->confirmationPolicy) < 2) {
                $bad[] = $skill->key.':weak_archive';
            }
        }

        return $bad === []
            ? $this->check('confirmation_policies', 'PASS', 'Confirmation policies valid.', [])
            : $this->check('confirmation_policies', 'FAIL', 'Invalid confirmation policies.', ['bad' => $bad]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkInternalNotInCatalog(): array
    {
        $exposed = [];
        foreach ($this->skills->all(false) as $skill) {
            if (str_contains($skill->capability, 'internal') || $skill->capability === 'content_project.sync_items') {
                $exposed[] = $skill->key;
            }
        }

        return $exposed === []
            ? $this->check('internal_exposure', 'PASS', 'Internal capabilities not in public catalog.', [])
            : $this->check('internal_exposure', 'FAIL', 'Internal skills exposed.', ['exposed' => $exposed]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkBuiltinDatasets(bool $fixSafe): array
    {
        if ($fixSafe && $this->datasets !== null) {
            try {
                $this->datasets->install();
            } catch (Throwable) {
                // continue to inspect
            }
        }
        try {
            $keys = SeoAgentEvaluationDataset::query()
                ->whereIn('key', ['core-routing', 'core-capability-coverage'])
                ->pluck('key')
                ->all();
            $missing = array_values(array_diff(['core-routing', 'core-capability-coverage'], $keys));

            return $missing === []
                ? $this->check('builtin_datasets', 'PASS', 'core-routing + core-capability-coverage present.', [])
                : $this->check('builtin_datasets', $fixSafe ? 'FAIL' : 'WARN', 'Datasets missing — run install-builtin.', ['missing' => $missing]);
        } catch (Throwable $e) {
            return $this->check('builtin_datasets', 'WARN', $e->getMessage(), []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPacks(bool $fixSafe): array
    {
        if ($fixSafe) {
            try {
                $this->packs?->invalidate();
                $this->skills->invalidate();
            } catch (Throwable) {
            }
        }
        try {
            $n = count($this->packs?->listSummaries() ?? []);

            return $this->check('packs', 'PASS', "pack_rows={$n}", ['count' => $n]);
        } catch (Throwable $e) {
            return $this->check('packs', 'WARN', $e->getMessage(), []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCoverage(): array
    {
        $report = $this->audit->audit();
        $critical = (int) ($report['summary']['critical_gaps'] ?? 0);

        return $critical === 0
            ? $this->check('capability_coverage', 'PASS', 'No critical P0 gaps.', $report['summary'])
            : $this->check('capability_coverage', 'FAIL', 'Critical P0 gaps remain.', $report['summary']);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkObservabilityStorage(): array
    {
        $dir = storage_path('app/agent-audits');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $writable = is_dir($dir) && is_writable($dir);

        return $writable
            ? $this->check('observability_storage', 'PASS', 'agent-audits writable.', ['path' => $dir])
            : $this->check('observability_storage', 'WARN', 'agent-audits not writable.', ['path' => $dir]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkAutomationCommand(): array
    {
        $path = dirname(__DIR__, 3).'/Console/DispatchDueAgentAutomationsCommand.php';

        return is_file($path)
            ? $this->check('automation_scheduler_command', 'PASS', 'DispatchDueAgentAutomationsCommand present.', [])
            : $this->check('automation_scheduler_command', 'FAIL', 'Automation dispatch command missing.', []);
    }

    private function rank(string $policy): int
    {
        return match ($policy) {
            'none' => 0,
            'preview' => 1,
            'confirm' => 2,
            'destructive' => 3,
            default => -1,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function check(string $id, string $status, string $message, array $meta): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'message' => $message,
            'meta' => $meta,
        ];
    }
}
