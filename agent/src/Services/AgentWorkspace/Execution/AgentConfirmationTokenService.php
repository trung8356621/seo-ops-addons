<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Agent Workspace confirmation tokens — raw token never persisted/logged.
 * Only hash stored on execution row; payload lives in cache (one-time).
 */
final class AgentConfirmationTokenService
{
    private const TTL_SECONDS = 900;

    /** @var array<string, array<string, mixed>> */
    private static array $memory = [];

    /**
     * @param  array{
     *     actor_id: int,
     *     tenant_ref: string,
     *     site_ref: string,
     *     conversation_id: int,
     *     execution_ref: string,
     *     skill_key: string,
     *     capability_key: string,
     *     input_hash: string,
     *     gateway_state?: string|null
     * }  $bind
     * @return array{token: string, hash: string, expires_at: string}
     */
    public function issue(array $bind): array
    {
        $token = 'awconf_'.Str::lower((string) Str::ulid());
        $expiresAt = (new \DateTimeImmutable('now'))->modify('+'.self::TTL_SECONDS.' seconds');
        $hash = $this->hashToken($token);

        $this->storePut($this->cacheKey($hash), [
            'actor_id' => (int) $bind['actor_id'],
            'tenant_ref' => (string) $bind['tenant_ref'],
            'site_ref' => (string) $bind['site_ref'],
            'conversation_id' => (int) $bind['conversation_id'],
            'execution_ref' => (string) $bind['execution_ref'],
            'skill_key' => (string) $bind['skill_key'],
            'capability_key' => (string) $bind['capability_key'],
            'input_hash' => (string) $bind['input_hash'],
            'gateway_state' => isset($bind['gateway_state']) ? (string) $bind['gateway_state'] : null,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
            'consumed' => false,
            'nonce' => (string) Str::ulid(),
        ]);

        return [
            'token' => $token,
            'hash' => $hash,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param  array{
     *     actor_id: int,
     *     tenant_ref: string,
     *     site_ref: string,
     *     conversation_id: int,
     *     execution_ref: string,
     *     skill_key: string,
     *     capability_key: string,
     *     input_hash: string,
     *     gateway_state?: string|null,
     *     stored_hash?: string|null
     * }  $expected
     * @return 'ok'|'invalid'|'expired'|'stale'|'actor_mismatch'|'site_mismatch'|'conversation_mismatch'|'input_mismatch'|'already_used'
     */
    public function validate(string $token, array $expected): string
    {
        if ($token === '') {
            return 'invalid';
        }

        $isRawToken = str_starts_with($token, 'awconf_');
        $isHashToken = strlen($token) === 64 && ctype_xdigit($token);
        if (! $isRawToken && ! $isHashToken) {
            return 'invalid';
        }

        // Support both:
        // - Raw token: "awconf_..." (legacy)
        // - confirmation_ref hash: 64-hex string (new UX: no raw token in client)
        $hash = $isRawToken ? $this->hashToken($token) : $token;
        if (isset($expected['stored_hash']) && is_string($expected['stored_hash']) && $expected['stored_hash'] !== '') {
            if (! hash_equals((string) $expected['stored_hash'], $hash)) {
                return 'invalid';
            }
        }

        $payload = $this->storeGet($this->cacheKey($hash));
        if (! is_array($payload)) {
            return 'invalid';
        }

        if (($payload['consumed'] ?? false) === true) {
            return 'already_used';
        }

        $expiresAt = (string) ($payload['expires_at'] ?? '');
        if ($expiresAt !== '' && new \DateTimeImmutable('now') > new \DateTimeImmutable($expiresAt)) {
            return 'expired';
        }

        if ((int) ($payload['actor_id'] ?? 0) !== (int) $expected['actor_id']) {
            return 'actor_mismatch';
        }
        if ((string) ($payload['site_ref'] ?? '') !== (string) $expected['site_ref']) {
            return 'site_mismatch';
        }
        if ((string) ($payload['tenant_ref'] ?? '') !== (string) $expected['tenant_ref']) {
            return 'site_mismatch';
        }
        if ((int) ($payload['conversation_id'] ?? 0) !== (int) $expected['conversation_id']) {
            return 'conversation_mismatch';
        }
        if ((string) ($payload['execution_ref'] ?? '') !== (string) $expected['execution_ref']) {
            return 'stale';
        }
        if ((string) ($payload['skill_key'] ?? '') !== (string) $expected['skill_key']
            || (string) ($payload['capability_key'] ?? '') !== (string) $expected['capability_key']) {
            return 'stale';
        }
        if (! hash_equals((string) ($payload['input_hash'] ?? ''), (string) $expected['input_hash'])) {
            return 'input_mismatch';
        }

        $expectedGateway = isset($expected['gateway_state']) ? (string) $expected['gateway_state'] : '';
        $storedGateway = (string) ($payload['gateway_state'] ?? '');
        if ($expectedGateway !== '' && $storedGateway !== '' && ! hash_equals($storedGateway, $expectedGateway)) {
            return 'stale';
        }

        return 'ok';
    }

    public function consume(string $token): void
    {
        if ($token === '') {
            return;
        }

        $isRawToken = str_starts_with($token, 'awconf_');
        $isHashToken = strlen($token) === 64 && ctype_xdigit($token);
        if (! $isRawToken && ! $isHashToken) {
            return;
        }

        $hash = $isRawToken ? $this->hashToken($token) : $token;
        $payload = $this->storeGet($this->cacheKey($hash));
        if (! is_array($payload)) {
            return;
        }

        $payload['consumed'] = true;
        $this->storePut($this->cacheKey($hash), $payload);
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function hashInput(array $input): string
    {
        $normalized = $input;
        ksort($normalized);

        return hash('sha256', (string) json_encode($normalized));
    }

    public function maskHash(?string $hash): string
    {
        if ($hash === null || $hash === '') {
            return '—';
        }

        return substr($hash, 0, 8).'…'.substr($hash, -4);
    }

    public static function clearMemory(): void
    {
        self::$memory = [];
    }

    private function cacheKey(string $hash): string
    {
        return 'seo_agent_workspace:confirmation:'.$hash;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storePut(string $key, array $payload): void
    {
        try {
            if (function_exists('app') && app()->bound('cache')) {
                Cache::put($key, $payload, self::TTL_SECONDS);

                return;
            }
        } catch (Throwable) {
            // Fall through to memory store for pure PHPUnit.
        }

        self::$memory[$key] = $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storeGet(string $key): ?array
    {
        try {
            if (function_exists('app') && app()->bound('cache')) {
                $payload = Cache::get($key);

                return is_array($payload) ? $payload : null;
            }
        } catch (Throwable) {
            // Fall through.
        }

        $payload = self::$memory[$key] ?? null;

        return is_array($payload) ? $payload : null;
    }
}
