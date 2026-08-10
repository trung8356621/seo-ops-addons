<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectAutomationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentErrorCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;

/**
 * Validates plan drafts before persist — policy, DAG, limits, unsafe steps.
 */
final class ContentProjectCanonicalPlanValidator
{
    /** @var list<string> */
    private const UNSAFE_CAPABILITIES = [
        'execute_sql',
        'raw_sql',
        'shell_exec',
        'system',
    ];

    /** @var list<string> */
    private const WRITE_PREFIXES = [
        'content_project.create',
        'content_project.update',
        'content_project.add_items',
        'content_project.generate',
        'content_project.rerun',
        'content_project.start_review',
        'content_project.approve',
        'content_project.schedule',
        'content_project.auto_schedule',
        'content_project.publish',
        'content_project.archive',
        'content_project.restore',
    ];

    public function __construct(
        private readonly ContentProjectCapabilityRegistry $registry,
        private readonly ContentProjectAutomationPolicyService $policyService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{code: string, message: string}>
     */
    public function validate(
        array $steps,
        ?ContentProjectAutomationPolicy $policy = null,
        ?string $automationLevel = null,
    ): array {
        $errors = [];
        $maxSteps = $this->configInt('seo-content-ai.content_project_agent.planner.max_steps', 20);
        $maxWrite = $this->configInt('seo-content-ai.content_project_agent.planner.max_write_steps', 15);
        $maxPublish = $this->configInt('seo-content-ai.content_project_agent.planner.max_publish_steps', 1);
        $maxArchive = $this->configInt('seo-content-ai.content_project_agent.planner.max_archive_steps', 1);

        if (count($steps) > $maxSteps) {
            $errors[] = ['code' => AgentErrorCodes::PLAN_INVALID_INPUT, 'message' => 'Plan exceeds max steps.'];
        }

        $writeCount = 0;
        $publishCount = 0;
        $archiveCount = 0;
        $refs = [];

        foreach ($steps as $index => $step) {
            $stepType = (string) ($step['step_type'] ?? AgentPlanStepType::CAPABILITY);
            $ref = (string) ($step['step_ref'] ?? 'step_'.$index);
            $refs[$ref] = $index;

            if ($stepType === AgentPlanStepType::WAIT_OPERATION || $stepType === AgentPlanStepType::WAIT_CONDITION) {
                continue;
            }

            $capability = trim((string) ($step['capability'] ?? ''));
            if ($capability === '') {
                $errors[] = ['code' => AgentErrorCodes::PLAN_INVALID_INPUT, 'message' => "Step {$index} missing capability."];

                continue;
            }

            if (in_array($capability, self::UNSAFE_CAPABILITIES, true)) {
                $errors[] = ['code' => AgentErrorCodes::PLAN_UNSAFE_STEP, 'message' => "Unsafe capability: {$capability}."];

                continue;
            }

            $known = $this->registry->get($capability) !== null
                || in_array($capability, ContentProjectAgentGateway::READ_CAPABILITIES, true);

            if (! $known) {
                $errors[] = ['code' => AgentErrorCodes::PLAN_INVALID_CAPABILITY, 'message' => "Unknown capability: {$capability}."];

                continue;
            }

            if ($policy !== null && ! $this->policyService->isCapabilityAllowed($policy, $capability)) {
                $errors[] = ['code' => AgentErrorCodes::PLAN_INVALID_CAPABILITY, 'message' => "Capability blocked by policy: {$capability}."];
            }

            if ($this->isWrite($capability)) {
                $writeCount++;
            }
            if (str_contains($capability, 'publish')) {
                $publishCount++;
            }
            if ($capability === 'content_project.archive') {
                $archiveCount++;
            }
        }

        if ($writeCount > $maxWrite) {
            $errors[] = ['code' => AgentErrorCodes::PLAN_INVALID_INPUT, 'message' => 'Plan exceeds max write steps.'];
        }
        if ($publishCount > $maxPublish) {
            $errors[] = ['code' => AgentErrorCodes::PLAN_INVALID_INPUT, 'message' => 'Plan exceeds max publish steps.'];
        }
        if ($archiveCount > $maxArchive) {
            $errors[] = ['code' => AgentErrorCodes::PLAN_INVALID_INPUT, 'message' => 'Plan exceeds max archive steps.'];
        }

        $cycle = $this->detectCycle($steps);
        if ($cycle !== null) {
            $errors[] = ['code' => AgentErrorCodes::PLAN_INVALID_INPUT, 'message' => 'Dependency cycle detected: '.$cycle];
        }

        return $errors;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function detectCycle(array $steps): ?string
    {
        $graph = [];
        foreach ($steps as $index => $step) {
            $ref = (string) ($step['step_ref'] ?? 'step_'.$index);
            $deps = $step['depends_on_step_refs'] ?? [];
            $graph[$ref] = is_array($deps) ? array_map('strval', $deps) : [];
        }

        $visited = [];
        $stack = [];

        foreach (array_keys($graph) as $node) {
            if ($this->dfsCycle($node, $graph, $visited, $stack)) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $graph
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $stack
     */
    private function dfsCycle(string $node, array $graph, array &$visited, array &$stack): bool
    {
        if (isset($stack[$node])) {
            return true;
        }
        if (isset($visited[$node])) {
            return false;
        }

        $visited[$node] = true;
        $stack[$node] = true;

        foreach ($graph[$node] ?? [] as $dep) {
            if ($this->dfsCycle($dep, $graph, $visited, $stack)) {
                return true;
            }
        }

        unset($stack[$node]);

        return false;
    }

    private function isWrite(string $capability): bool
    {
        foreach (self::WRITE_PREFIXES as $prefix) {
            if ($capability === $prefix || str_starts_with($capability, $prefix)) {
                return true;
            }
        }

        return $this->registry->get($capability) !== null
            && ! in_array($capability, ContentProjectAgentGateway::READ_CAPABILITIES, true);
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = (int) config($key, $default);

            return $value > 0 ? $value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
