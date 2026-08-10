<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentExecution;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;

/**
 * Manager/admin diagnostics for Agent Skills — no credential exposure.
 */
final class AgentCapabilityDiagnosticsService
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentSkillAvailabilityService $availability,
        private readonly CanonicalCapabilityRegistry $capabilities,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(AgentWorkspaceContext $context): array
    {
        $rows = [];
        foreach ($this->skills->all(includeHidden: true) as $skill) {
            $availability = $this->availability->resolve($skill, $context->toAvailabilityContext());
            $cap = $this->capabilities->get($skill->capability);
            $last = SeoAgentExecution::query()
                ->where('skill_key', $skill->key)
                ->orderByDesc('id')
                ->first();

            $rows[] = [
                'skill_key' => $skill->key,
                'slash_command' => $skill->slashCommand,
                'name' => $skill->name,
                'capability' => $skill->capability,
                'availability' => $availability->toArray(),
                'scopes' => $skill->requiredScopes,
                'provider_dependency' => $skill->availabilityPolicy['provider'] ?? null,
                'extension_dependency' => $skill->availabilityPolicy['extension'] ?? null,
                'confirmation_policy' => $skill->confirmationPolicy,
                'last_execution' => $last ? [
                    'status' => $last->status,
                    'error_code' => $last->error_code,
                    'completed_at' => $last->completed_at?->toIso8601String(),
                    'operation_ref' => $last->operation_ref,
                ] : null,
                'input_schema' => $skill->inputSchema,
                'capability_schema' => is_array($cap) ? [
                    'name' => $cap['name'] ?? null,
                    'risk_level' => $cap['risk_level'] ?? null,
                    'confirmation' => $cap['confirmation'] ?? null,
                    'input_schema' => $cap['input_schema'] ?? null,
                ] : null,
            ];
        }

        return $rows;
    }
}
