<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Models\SaRpaAdminSession;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Issue / resolve / revoke RPA Admin sessions.
 * Password verified against Core User; token never stored plaintext.
 * Request body user_id is ignored — identity comes from credentials or token.
 */
final class RpaAdminSessionService
{
    public const TOKEN_TTL_HOURS = 72;

    public const REQUEST_USER_KEY = 'rpa_admin_user';

    public const REQUEST_SESSION_KEY = 'rpa_admin_session';

    public function __construct(
        private readonly RpaAdminRoleGate $roles = new RpaAdminRoleGate,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: true, token: string, user: array<string, mixed>, expires_at: string}|array{ok: false, errors: list<string>}
     */
    public function login(array $input): array
    {
        if (! $this->tablesReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        // Never trust client-supplied user_id.
        unset($input['user_id'], $input['id']);

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if ($email === '' || $password === '') {
            return ['ok' => false, 'errors' => ['credentials_required']];
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User || ! Hash::check($password, (string) $user->password)) {
            return ['ok' => false, 'errors' => ['invalid_credentials']];
        }

        if (! $this->roles->allows($user)) {
            return ['ok' => false, 'errors' => [$this->roles->denyReason($user)]];
        }

        $plain = Str::random(48);
        $hash = hash('sha256', $plain);
        $expires = now()->addHours(self::TOKEN_TTL_HOURS);

        SaRpaAdminSession::query()->create([
            'user_id' => (int) $user->id,
            'token_hash' => $hash,
            'token_hint' => substr($plain, -6),
            'expires_at' => $expires,
            'last_seen_at' => now(),
            'client_label' => isset($input['client_label'])
                ? substr(trim((string) $input['client_label']), 0, 128)
                : null,
        ]);

        return [
            'ok' => true,
            'token' => $plain,
            'expires_at' => $expires->toIso8601String(),
            'user' => $this->userPublic($user),
        ];
    }

    /**
     * @return array{ok: true, user: array<string, mixed>, expires_at: string}|array{ok: false, errors: list<string>}
     */
    public function session(?string $bearerToken): array
    {
        $resolved = $this->resolve($bearerToken);
        if (! ($resolved['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $resolved['errors'] ?? ['unauthenticated']];
        }

        /** @var User $user */
        $user = $resolved['user'];
        /** @var SaRpaAdminSession $session */
        $session = $resolved['session'];

        return [
            'ok' => true,
            'user' => $this->userPublic($user),
            'expires_at' => $session->expires_at->toIso8601String(),
        ];
    }

    /**
     * @return array{ok: true}|array{ok: false, errors: list<string>}
     */
    public function logout(?string $bearerToken): array
    {
        $resolved = $this->resolve($bearerToken);
        if (! ($resolved['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $resolved['errors'] ?? ['unauthenticated']];
        }

        /** @var SaRpaAdminSession $session */
        $session = $resolved['session'];
        $session->revoked_at = now();
        $session->save();

        return ['ok' => true];
    }

    /**
     * @return array{ok: true, user: User, session: SaRpaAdminSession}|array{ok: false, errors: list<string>}
     */
    public function resolve(?string $bearerToken): array
    {
        if (! $this->tablesReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $token = trim((string) $bearerToken);
        if ($token === '') {
            return ['ok' => false, 'errors' => ['unauthenticated']];
        }

        $hash = hash('sha256', $token);
        $session = SaRpaAdminSession::query()->where('token_hash', $hash)->first();
        if (! $session instanceof SaRpaAdminSession) {
            return ['ok' => false, 'errors' => ['unauthenticated']];
        }
        if ($session->revoked_at !== null) {
            return ['ok' => false, 'errors' => ['session_revoked']];
        }
        if ($session->expires_at === null || $session->expires_at->isPast()) {
            return ['ok' => false, 'errors' => ['session_expired']];
        }

        $user = User::query()->find((int) $session->user_id);
        if (! $user instanceof User || ! $this->roles->allows($user)) {
            return ['ok' => false, 'errors' => [$this->roles->denyReason($user)]];
        }

        $session->last_seen_at = now();
        $session->save();

        return ['ok' => true, 'user' => $user, 'session' => $session];
    }

    /**
     * @return array{id: int, email: string, name: string, role: string}
     */
    private function userPublic(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'email' => (string) $user->email,
            'name' => (string) $user->name,
            'role' => (string) $user->role,
        ];
    }

    private function tablesReady(): bool
    {
        try {
            return Schema::connection(SocialAutomationServiceConfig::CONNECTION)
                ->hasTable('sa_rpa_admin_sessions');
        } catch (Throwable) {
            return false;
        }
    }
}
