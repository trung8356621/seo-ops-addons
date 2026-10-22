<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationApiStatus;

final class RpaAdminAuthController extends Controller
{
    public function __construct(
        private readonly RpaAdminSessionService $sessions,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $payload = $request->all();
        if (! is_array($payload)) {
            $payload = [];
        }
        // Identity is derived server-side from email/password only.
        unset($payload['user_id'], $payload['id'], $payload['role']);

        $result = $this->sessions->login($payload);
        $ok = (bool) ($result['ok'] ?? false);

        return response()->json(
            $ok
                ? [
                    'ok' => true,
                    'token' => $result['token'],
                    'expires_at' => $result['expires_at'],
                    'user' => $result['user'],
                ]
                : ['ok' => false, 'errors' => $result['errors'] ?? ['login_failed']],
            SocialAutomationApiStatus::fromResult($ok, $result['errors'] ?? [])
        );
    }

    public function session(Request $request): JsonResponse
    {
        $token = $this->bearer($request);
        $result = $this->sessions->session($token);
        $ok = (bool) ($result['ok'] ?? false);

        return response()->json(
            $ok
                ? ['ok' => true, 'user' => $result['user'], 'expires_at' => $result['expires_at']]
                : ['ok' => false, 'errors' => $result['errors'] ?? ['unauthenticated']],
            SocialAutomationApiStatus::fromResult($ok, $result['errors'] ?? [])
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->bearer($request);
        $result = $this->sessions->logout($token);
        $ok = (bool) ($result['ok'] ?? false);

        return response()->json(
            $ok ? ['ok' => true] : ['ok' => false, 'errors' => $result['errors'] ?? ['unauthenticated']],
            SocialAutomationApiStatus::fromResult($ok, $result['errors'] ?? [])
        );
    }

    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) === 1) {
            return $m[1];
        }

        $alt = trim((string) $request->header('X-Rpa-Admin-Token', ''));

        return $alt !== '' ? $alt : null;
    }
}
