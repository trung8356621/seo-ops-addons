<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationApiStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate RPA Admin via opaque user session token (not Runner service credentials).
 */
final class EnsureRpaAdminUserApi
{
    public function __construct(
        private readonly RpaAdminSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractBearer($request);
        $resolved = $this->sessions->resolve($token);
        if (! ($resolved['ok'] ?? false)) {
            $errors = $resolved['errors'] ?? ['unauthenticated'];

            return response()->json(
                ['ok' => false, 'errors' => $errors],
                SocialAutomationApiStatus::fromResult(false, $errors)
            );
        }

        // Ignore any client-supplied identity fields.
        $request->request->remove('user_id');
        $request->request->remove('id');

        $request->attributes->set(RpaAdminSessionService::REQUEST_USER_KEY, $resolved['user']);
        $request->attributes->set(RpaAdminSessionService::REQUEST_SESSION_KEY, $resolved['session']);

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) === 1) {
            return $m[1];
        }

        $alt = trim((string) $request->header('X-Rpa-Admin-Token', ''));

        return $alt !== '' ? $alt : null;
    }
}
