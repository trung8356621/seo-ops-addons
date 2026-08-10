<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;

/**
 * Resolve slash-command UX metadata against CanonicalCapabilityRegistry (SoT).
 * Local-only presentation commands bypass the registry explicitly.
 */
final class AgentCliCapabilityGate
{
    public function __construct(
        private readonly CanonicalCapabilityRegistry $canonical,
        private readonly AgentSkillRegistry $skills,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   reason?: string,
     *   capability_key?: string|null,
     *   skill_key?: string|null,
     *   requires_confirmation?: bool,
     *   confirmation_policy?: string,
     *   local_only?: bool
     * }
     */
    public function resolve(string $command, AgentWorkspaceContext $context): array
    {
        $definition = AgentCliCommandCatalog::get($command);
        if ($definition === null) {
            return ['ok' => false, 'reason' => 'unknown_command'];
        }

        $localOnly = (bool) ($definition['local_only'] ?? false);
        $skillKey = $definition['skill_key'] ?? null;

        if ($localOnly || $skillKey === null || $skillKey === '') {
            return [
                'ok' => true,
                'local_only' => true,
                'capability_key' => null,
                'skill_key' => is_string($skillKey) ? $skillKey : null,
                'requires_confirmation' => false,
                'confirmation_policy' => 'none',
            ];
        }

        $skill = $this->skills->get((string) $skillKey);
        if ($skill === null) {
            return ['ok' => false, 'reason' => 'skill_missing:'.$skillKey];
        }

        $capabilityKey = trim((string) ($definition['capability_key'] ?? $skill->capability));
        if ($capabilityKey === '') {
            return ['ok' => false, 'reason' => 'capability_missing'];
        }

        // Meta agent.* surface (help / new_chat) — local UI, not MCP.
        if (str_starts_with($capabilityKey, 'agent.')) {
            return [
                'ok' => true,
                'local_only' => true,
                'capability_key' => $capabilityKey,
                'skill_key' => $skill->key,
                'requires_confirmation' => false,
                'confirmation_policy' => 'none',
            ];
        }

        $cap = $this->canonical->get($capabilityKey);
        if ($cap !== null) {
            if (! (bool) ($cap['enabled'] ?? true)
                || (bool) ($cap['internal'] ?? false)
                || ! (bool) ($cap['agent_exposed'] ?? true)
            ) {
                return ['ok' => false, 'reason' => 'capability_not_exposed:'.$capabilityKey];
            }

            $scopesToCheck = $skill->requiredScopes !== []
                ? $skill->requiredScopes
                : (is_array($cap['scopes'] ?? null) ? $cap['scopes'] : []);
            $scopeError = $this->assertScopes($context, $scopesToCheck);
            if ($scopeError !== null) {
                return ['ok' => false, 'reason' => $scopeError];
            }

            $contextError = $this->assertRequiredContext(
                $context,
                is_array($cap['required_context'] ?? null) ? $cap['required_context'] : ['site_ref'],
            );
            if ($contextError !== null) {
                return ['ok' => false, 'reason' => $contextError];
            }

            $requires = (bool) ($cap['confirmation_requirement'] ?? false);

            return [
                'ok' => true,
                'local_only' => false,
                'capability_key' => $capabilityKey,
                'skill_key' => $skill->key,
                'requires_confirmation' => $requires,
                'confirmation_policy' => $requires ? 'confirm' : 'none',
            ];
        }

        if (in_array($capabilityKey, ContentProjectAgentGateway::READ_CAPABILITIES, true)) {
            $scopeError = $this->assertScopes($context, $skill->requiredScopes);
            if ($scopeError !== null) {
                return ['ok' => false, 'reason' => $scopeError];
            }

            $contextError = $this->assertRequiredContext($context, ['site_ref']);
            if ($contextError !== null) {
                return ['ok' => false, 'reason' => $contextError];
            }

            return [
                'ok' => true,
                'local_only' => false,
                'capability_key' => $capabilityKey,
                'skill_key' => $skill->key,
                'requires_confirmation' => false,
                'confirmation_policy' => 'none',
            ];
        }

        return ['ok' => false, 'reason' => 'capability_unavailable:'.$capabilityKey];
    }

    /**
     * Prefer canonical confirmation when capability is registered; otherwise skill policy.
     *
     * @return array{requires: bool, policy: string}
     */
    public function confirmationForCapability(string $capabilityKey, string $skillConfirmationPolicy): array
    {
        $cap = $this->canonical->get($capabilityKey);
        if ($cap !== null) {
            $requires = (bool) ($cap['confirmation_requirement'] ?? false);

            return [
                'requires' => $requires,
                'policy' => $requires ? 'confirm' : 'none',
            ];
        }

        if (in_array($capabilityKey, ContentProjectAgentGateway::READ_CAPABILITIES, true)) {
            return ['requires' => false, 'policy' => 'none'];
        }

        $requires = in_array($skillConfirmationPolicy, ['preview', 'confirm', 'destructive'], true);

        return [
            'requires' => $requires,
            'policy' => $requires ? $skillConfirmationPolicy : 'none',
        ];
    }

    /**
     * @param  list<mixed>  $scopes
     */
    private function assertScopes(AgentWorkspaceContext $context, array $scopes): ?string
    {
        $required = [];
        foreach ($scopes as $scope) {
            $s = trim((string) $scope);
            if ($s !== '') {
                $required[] = $s;
            }
        }

        if ($required === []) {
            return null;
        }

        $actorScopes = $context->scopes;
        foreach ($required as $scope) {
            if (! in_array($scope, $actorScopes, true)) {
                return 'missing_scope:'.$scope;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $requiredContext
     */
    private function assertRequiredContext(AgentWorkspaceContext $context, array $requiredContext): ?string
    {
        foreach ($requiredContext as $key) {
            $field = trim((string) $key);
            if ($field === '' || $field === 'tenant_ref') {
                continue;
            }

            $value = match ($field) {
                'site_ref' => $context->siteRef,
                'project_ref' => $context->projectRef,
                'workspace_ref' => $context->workspaceRef,
                'article_ref' => $context->articleRef,
                default => null,
            };

            if ($value === null || trim((string) $value) === '') {
                return 'missing_context:'.$field;
            }
        }

        return null;
    }
}
