<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceContextService;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/**
 * Canonical Agent scope evaluator — PAT empty abilities fail closed.
 * Web session uses RBAC scopes; system actors pass explicit scopes only.
 */
final class AgentScopeEvaluator
{
    /** @var list<string> */
    public const KNOWN_SCOPES = [
        'content-project:read',
        'content-project:write',
        'content-project:generate',
        'content-project:review',
        'content-project:schedule',
        'content-project:publish',
        'content-project:archive',
        'content-project:admin',
    ];

    public function __construct(
        private readonly AgentWorkspaceContextService $workspaceContext,
    ) {}

    /**
     * @return list<string>
     */
    public function resolveForRequest(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return [];
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            return $this->scopesFromPersonalAccessToken($token);
        }

        // TransientToken / session cookie — not PAT abilities semantics.
        if ($token instanceof TransientToken || $token === null) {
            return $this->workspaceContext->scopesForAuthenticatedUser($user);
        }

        // Unknown token type: fail closed for Agent API write surface.
        return [];
    }

    /**
     * Trusted system/queue actor — never invent admin; caller must pass exact scopes.
     *
     * @param  list<string>  $explicitScopes
     * @return list<string>
     */
    public function forSystemActor(array $explicitScopes): array
    {
        $allowed = [];
        foreach ($explicitScopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '' && in_array($scope, self::KNOWN_SCOPES, true)) {
                $allowed[] = $scope;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @return list<string>
     */
    public function scopesFromPersonalAccessToken(PersonalAccessToken $token): array
    {
        $abilities = $token->abilities;
        if (! is_array($abilities) || $abilities === []) {
            return [];
        }

        if (in_array('*', $abilities, true)) {
            return self::KNOWN_SCOPES;
        }

        $scopes = [];
        foreach (self::KNOWN_SCOPES as $scope) {
            if (in_array($scope, $abilities, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * Normalize scopes stored on a plan — never elevate.
     *
     * @param  mixed  $raw
     * @return list<string>
     */
    public function normalizeStoredScopes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return $this->forSystemActor(array_map('strval', $raw));
    }
}
