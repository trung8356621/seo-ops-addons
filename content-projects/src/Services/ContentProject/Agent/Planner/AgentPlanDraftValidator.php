<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use InvalidArgumentException;

/**
 * Data-only multi-step plan draft validation — no auto execute.
 */
final class AgentPlanDraftValidator
{
    /**
     * @param  list<array{capability: string, input?: array<string, mixed>}>  $steps
     */
    public function __construct(
        public readonly array $steps,
        public readonly int $maxSteps = 20,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function validatePlan(array $payload, ContentProjectCapabilityRegistry $registry): array
    {
        $maxSteps = self::maxSteps();
        $steps = $payload['steps'] ?? null;

        if (! is_array($steps) || $steps === []) {
            return ['Plan must include non-empty steps array.'];
        }

        if (count($steps) > $maxSteps) {
            return ['Plan exceeds max steps ('.$maxSteps.').'];
        }

        $errors = [];
        $capabilities = [];
        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                $errors[] = 'Step '.$index.' must be an object.';

                continue;
            }

            $stepType = (string) ($step['step_type'] ?? AgentPlanStepType::CAPABILITY);
            if (in_array($stepType, [AgentPlanStepType::WAIT_OPERATION, AgentPlanStepType::WAIT_CONDITION], true)) {
                continue;
            }

            $capability = trim((string) ($step['capability'] ?? ''));
            if ($capability === '') {
                $errors[] = 'Step '.$index.' missing capability.';

                continue;
            }

            if ($registry->get($capability) === null && ! in_array($capability, ContentProjectAgentGateway::READ_CAPABILITIES, true)) {
                $errors[] = 'Step '.$index.' unknown capability: '.$capability;
            }

            $capabilities[] = $capability;
        }

        if (in_array('content_project.restore', $capabilities, true)
            && (in_array('content_project.generate', $capabilities, true)
                || in_array('content_project.rerun', $capabilities, true))) {
            $errors[] = 'Plan cannot combine restore and generate/rerun in same draft without separate steps.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, ContentProjectCapabilityRegistry $registry): self
    {
        $errors = self::validatePlan($payload, $registry);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        /** @var list<array{capability: string, input?: array<string, mixed>}> $steps */
        $steps = $payload['steps'];

        return new self(
            steps: $steps,
            maxSteps: self::maxSteps(),
        );
    }

    private static function maxSteps(): int
    {
        $default = 20;
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = (int) config('seo-content-ai.content_project_agent.planner.max_steps', $default);

            return $value > 0 ? $value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
