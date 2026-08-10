<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\V1;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceVersion;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use Throwable;

/**
 * Registry-based capability coverage audit — no fragile source regex.
 */
final class AgentCapabilityCoverageAuditService
{
    public function __construct(
        private readonly CanonicalCapabilityRegistry $capabilities,
        private readonly AgentSkillRegistry $skills,
        private readonly ?AgentPackRegistry $packs = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(?string $module = null, bool $onlyMissing = false): array
    {
        $capabilityNames = [];
        try {
            foreach ($this->capabilities->all() as $cap) {
                $name = (string) ($cap['name'] ?? '');
                if ($name !== '') {
                    $capabilityNames[$name] = $cap;
                }
            }
        } catch (Throwable) {
            $capabilityNames = [];
        }

        $skillByKey = [];
        $skillByCapability = [];
        $slashCommands = [];
        try {
            foreach ($this->skills->all(includeHidden: true) as $skill) {
                $skillByKey[$skill->key] = $skill;
                $skillByCapability[$skill->capability] = $skill;
                $slashCommands[$skill->slashCommand] = $skill->key;
            }
        } catch (Throwable) {
            // isolate
        }

        $readCaps = array_fill_keys(ContentProjectAgentGateway::READ_CAPABILITIES, true);

        $rows = [];
        $counts = [
            'modules' => [],
            'features' => 0,
            'complete' => 0,
            'partial' => 0,
            'missing' => 0,
            'internal' => 0,
            'deprecated' => 0,
            'critical_gaps' => 0,
        ];

        foreach (AgentCapabilityInventory::rows() as $inv) {
            if ($module !== null && $module !== '' && $inv['module'] !== $module) {
                continue;
            }

            $status = $this->classify($inv, $capabilityNames, $skillByKey, $skillByCapability, $readCaps);
            if ($onlyMissing && ! in_array($status['status'], ['missing', 'partial'], true)) {
                continue;
            }

            $row = array_merge($inv, $status);
            $rows[] = $row;
            $counts['features']++;
            $counts['modules'][$inv['module']] = true;
            $bucket = $status['status'];
            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            }
            if (($status['critical'] ?? false) === true) {
                $counts['critical_gaps']++;
            }
        }

        $counts['modules'] = count($counts['modules']);

        return [
            'ok' => true,
            'version' => AgentWorkspaceVersion::snapshot(),
            'generated_at' => gmdate(DATE_ATOM),
            'summary' => $counts,
            'rows' => $rows,
            'slash_conflicts' => $this->detectSlashConflicts($slashCommands),
            'pack_skill_count' => count($this->packs?->enabledSkillDefinitions() ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function writeJson(array $report, ?string $path = null): string
    {
        $path ??= storage_path('app/agent-audits/capability-coverage.json');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(
            $path,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );

        return $path;
    }

    /**
     * @param  array<string, mixed>  $inv
     * @param  array<string, array<string, mixed>>  $capabilityNames
     * @param  array<string, mixed>  $skillByKey
     * @param  array<string, mixed>  $skillByCapability
     * @param  array<string, bool>  $readCaps
     * @return array{status: string, gaps: list<string>, critical: bool, live_confirmation: ?string}
     */
    private function classify(
        array $inv,
        array $capabilityNames,
        array $skillByKey,
        array $skillByCapability,
        array $readCaps,
    ): array {
        if (($inv['operation_type'] ?? '') === 'internal' || ($inv['expected_status'] ?? '') === 'internal-only') {
            return ['status' => 'internal', 'gaps' => [], 'critical' => false, 'live_confirmation' => null];
        }
        if (($inv['expected_status'] ?? '') === 'deprecated') {
            return ['status' => 'deprecated', 'gaps' => [], 'critical' => false, 'live_confirmation' => null];
        }

        $gaps = [];
        $capKey = $inv['capability_key'] ?? null;
        $skillKey = $inv['skill_key'] ?? null;
        $op = (string) ($inv['operation_type'] ?? 'read');
        $gateway = (string) ($inv['gateway_exposure'] ?? '');

        $cap = is_string($capKey) ? ($capabilityNames[$capKey] ?? null) : null;
        $isLocal = $gateway === 'local' || (is_string($capKey) && str_starts_with($capKey, 'agent.'));

        if (is_string($capKey) && $capKey !== '' && ! $isLocal && $cap === null) {
            $gaps[] = 'missing_capability';
        }

        $skill = null;
        if (is_string($skillKey) && $skillKey !== '') {
            $skill = $skillByKey[$skillKey] ?? null;
            if ($skill === null) {
                $gaps[] = 'missing_skill';
            }
        }

        if ($op === 'read' && is_string($capKey) && ! $isLocal) {
            if (! isset($readCaps[$capKey]) && $cap === null) {
                $gaps[] = 'missing_read_surface';
            }
        }

        $liveConfirmation = $skill?->confirmationPolicy;
        if (in_array($op, ['write', 'destructive'], true) && $skill !== null) {
            $expected = (string) ($inv['confirmation_policy'] ?? 'preview');
            if ($this->rank($skill->confirmationPolicy) < $this->rank($expected)) {
                $gaps[] = 'confirmation_downgrade';
            }
            if ($op === 'destructive' && $this->rank($skill->confirmationPolicy) < $this->rank('confirm')) {
                $gaps[] = 'destructive_without_confirm';
            }
            if ($cap !== null && ($cap['confirmation_requirement'] ?? false) === true
                && $this->rank($skill->confirmationPolicy) < $this->rank('confirm')) {
                $gaps[] = 'canonical_confirmation_weaker';
            }
        }

        if ($gaps === []) {
            $status = 'complete';
        } elseif (in_array('missing_capability', $gaps, true) || in_array('missing_skill', $gaps, true)) {
            $status = count($gaps) >= 2 || ($cap === null && $skill === null) ? 'missing' : 'partial';
        } else {
            $status = 'partial';
        }

        $critical = ($inv['priority'] ?? '') === 'P0'
            && in_array($status, ['missing', 'partial'], true)
            && in_array($op, ['write', 'destructive', 'read'], true);

        return [
            'status' => $status,
            'gaps' => $gaps,
            'critical' => $critical,
            'live_confirmation' => $liveConfirmation,
        ];
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
     * @param  array<string, string>  $slashCommands
     * @return list<string>
     */
    private function detectSlashConflicts(array $slashCommands): array
    {
        // Registry already rejects conflicts at boot; report empty unless duplicate values appear.
        $seen = [];
        $conflicts = [];
        foreach ($slashCommands as $cmd => $key) {
            if (isset($seen[$cmd]) && $seen[$cmd] !== $key) {
                $conflicts[] = $cmd;
            }
            $seen[$cmd] = $key;
        }

        return $conflicts;
    }
}
