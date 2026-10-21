<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

/**
 * Secure Runner enrollment boundary (contract only — no open registration).
 *
 * OPS-SERVER / Admin mints `service_api_credentials` with Runner scopes via
 * existing Core Service API Access UI. Raw key returned once; never stored here.
 *
 * Required scopes for Runner credential:
 *   - social-automation:runner:agent
 *   - social-automation:runner:session
 *   - social-automation:runner:job
 *
 * Binding:
 *   1. Admin creates credential for Service slug=social-automation.
 *   2. Operator installs key on Runner out-of-band.
 *   3. Runner calls register/heartbeat with Bearer credential.
 *   4. Server binds credential_id → agent_uuid (first registration or explicit rebind).
 *
 * Forbidden:
 *   - Using services.service_key as Bearer
 *   - Unauthenticated /api/.../register
 *   - Self-service public enrollment without Admin mint
 *   - Cookie/password upload
 */
interface RunnerEnrollmentContract
{
    public const SCOPE_AGENT = 'social-automation:runner:agent';

    public const SCOPE_SESSION = 'social-automation:runner:session';

    public const SCOPE_JOB = 'social-automation:runner:job';

    /** @return list<string> */
    public static function requiredScopes(): array;
}
