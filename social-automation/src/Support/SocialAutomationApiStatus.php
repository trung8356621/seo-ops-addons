<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

/**
 * Map stable error codes → HTTP status. Business failures must not become 500.
 */
final class SocialAutomationApiStatus
{
    /**
     * @param  list<string>  $errors
     */
    public static function fromResult(bool $ok, array $errors = [], int $successStatus = 200): int
    {
        if ($ok) {
            return $successStatus;
        }

        $code = (string) ($errors[0] ?? 'validation_failed');

        return match (true) {
            in_array($code, [
                'unauthorized',
                'unauthenticated',
                'invalid_credentials',
                'credentials_required',
                'session_revoked',
                'session_expired',
            ], true) => 401,
            in_array($code, [
                'credential_mismatch',
                'credential_conflict',
                'cross_agent_forbidden',
                'forbidden',
                'agent_disabled',
                'role_staff_forbidden',
                'role_forbidden',
                'user_blocked',
                'system_user_forbidden',
            ], true) => 403,
            str_contains($code, 'not_found') => 404,
            in_array($code, [
                'version_digest_conflict',
                'package_kind_conflict',
                'invalid_transition',
                'stale_attempt',
                'attempt_fence_mismatch',
                'account_locked',
                'job_already_claimed',
            ], true) => 409,
            in_array($code, ['schema_unavailable', 'package_registry_unavailable', 'dependency_unavailable'], true) => 503,
            default => 422,
        };
    }
}
