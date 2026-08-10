<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillAvailability;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Resolves skill availability from capability registry + context + scopes.
 * No hard-coded per-skill branching in UI — UI consumes this service.
 */
final class AgentSkillAvailabilityService
{
    /** @var list<string> */
    private const META_CAPABILITIES = [
        'agent.help',
        'agent.new_chat',
        'agent.knowledge.list',
        'agent.knowledge.add',
        'agent.knowledge.search',
        'agent.knowledge.review_memory',
        'agent.knowledge.forget',
        'agent.knowledge.verify',
    ];

    public function __construct(
        private readonly CanonicalCapabilityRegistry $capabilities,
        private readonly ?AgentWorkspaceQuotaService $quotas = null,
    ) {}

    /**
     * @param  array{
     *     scopes?: list<string>,
     *     project_ref?: string|null,
     *     workspace_ref?: string|null,
     *     article_ref?: string|null,
     *     site_ref?: string|null,
     *     providers?: array<string, bool>,
     *     extensions?: array<string, bool>,
     *     feature_flags?: array<string, bool>,
     *     role?: string|null,
     *     executions_this_hour?: int|null
     * }  $context
     */
    public function resolve(AgentSkillDefinition $skill, array $context = []): AgentSkillAvailability
    {
        if ($skill->isHidden) {
            return AgentSkillAvailability::of(AgentSkillAvailability::HIDDEN, 'Skill is hidden.');
        }

        if ($skill->isComingSoon) {
            return AgentSkillAvailability::of(AgentSkillAvailability::COMING_SOON, 'Skill đang phát triển.');
        }

        $override = $skill->availabilityPolicy['status_override'] ?? null;
        if (is_string($override) && $override !== '') {
            if ($override === AgentSkillAvailability::AVAILABLE) {
                return AgentSkillAvailability::available();
            }

            return AgentSkillAvailability::of($override, 'Policy override.');
        }

        if (in_array($skill->capability, self::META_CAPABILITIES, true)) {
            return AgentSkillAvailability::available();
        }

        if (! $this->capabilityExists($skill->capability)) {
            return AgentSkillAvailability::of(
                AgentSkillAvailability::NOT_IMPLEMENTED,
                'Capability chưa được đăng ký: '.$skill->capability,
            );
        }

        $minRole = $skill->availabilityPolicy['min_role'] ?? null;
        if (is_string($minRole) && $minRole !== '' && ! $this->roleAllows($minRole, $context['role'] ?? null)) {
            return AgentSkillAvailability::of(
                AgentSkillAvailability::PERMISSION_DENIED,
                'Không đủ quyền để dùng skill này.',
            );
        }

        $scopes = $context['scopes'] ?? [];
        if (! is_array($scopes)) {
            $scopes = [];
        }
        foreach ($skill->requiredScopes as $required) {
            if ($required !== '' && ! in_array($required, $scopes, true) && ! $this->hasWildcardScope($scopes)) {
                return AgentSkillAvailability::of(
                    AgentSkillAvailability::PERMISSION_DENIED,
                    'Thiếu scope: '.$required,
                );
            }
        }

        $requiredContext = $skill->availabilityPolicy['requires_context'] ?? [];
        if (is_array($requiredContext)) {
            foreach ($requiredContext as $field) {
                $field = (string) $field;
                $value = $context[$field] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    // Soft: still usable if form can collect the field — mark available.
                    // Hard reject only when policy says fail_closed.
                    $failClosed = (bool) ($skill->availabilityPolicy['fail_closed_context'] ?? false);
                    if ($failClosed) {
                        return AgentSkillAvailability::of(
                            AgentSkillAvailability::WRONG_CONTEXT,
                            'Cần context: '.$field,
                        );
                    }
                }
            }
        }

        $provider = $skill->availabilityPolicy['provider'] ?? null;
        if (is_string($provider) && $provider !== '') {
            $providers = $context['providers'] ?? [];
            if (! is_array($providers) || ! (($providers[$provider] ?? false) === true)) {
                return AgentSkillAvailability::of(
                    AgentSkillAvailability::NOT_CONFIGURED,
                    strtoupper($provider).' provider chưa được cấu hình cho site này.',
                );
            }
        }

        $extension = $skill->availabilityPolicy['extension'] ?? null;
        if (is_string($extension) && $extension !== '') {
            $extensions = $context['extensions'] ?? [];
            if (! is_array($extensions) || ! (($extensions[$extension] ?? false) === true)) {
                return AgentSkillAvailability::of(
                    AgentSkillAvailability::EXTENSION_DISABLED,
                    'Extension chưa bật: '.$extension,
                );
            }
        }

        $flag = $skill->availabilityPolicy['feature_flag'] ?? null;
        if (is_string($flag) && $flag !== '') {
            $flags = $context['feature_flags'] ?? [];
            if (! is_array($flags) || ! (($flags[$flag] ?? false) === true)) {
                return AgentSkillAvailability::of(
                    AgentSkillAvailability::HIDDEN,
                    'Feature flag tắt: '.$flag,
                );
            }
        }

        if ($this->quotas !== null) {
            $executions = isset($context['executions_this_hour']) ? (int) $context['executions_this_hour'] : null;
            if ($executions !== null && $this->quotas->skillExecutionsExceeded($executions)) {
                return AgentSkillAvailability::of(
                    AgentSkillAvailability::QUOTA_EXCEEDED,
                    'Đã vượt hạn mức skill executions/hour.',
                );
            }
        }

        return AgentSkillAvailability::available();
    }

    private function capabilityExists(string $capability): bool
    {
        if ($capability === '') {
            return false;
        }

        if (in_array($capability, ContentProjectAgentGateway::READ_CAPABILITIES, true)) {
            return true;
        }

        return $this->capabilities->get($capability) !== null;
    }

    /**
     * @param  list<string>  $scopes
     */
    private function hasWildcardScope(array $scopes): bool
    {
        return in_array('*', $scopes, true)
            || in_array('content-project:*', $scopes, true);
    }

    private function roleAllows(string $minRole, ?string $actualRole): bool
    {
        if ($actualRole === null || $actualRole === '') {
            // Fail open when role not provided — backend Gateway still enforces.
            return true;
        }

        $rank = static function (string $role): int {
            return match ($role) {
                'admin', 'owner' => 100,
                SeoAccessControl::ROLE_MANAGER, 'manager' => 80,
                SeoAccessControl::ROLE_PLANNER, 'planner' => 60,
                SeoAccessControl::ROLE_CONTENT_MANAGER, 'content_manager', 'staff' => 40,
                default => 0,
            };
        };

        return $rank($actualRole) >= $rank($minRole);
    }
}
