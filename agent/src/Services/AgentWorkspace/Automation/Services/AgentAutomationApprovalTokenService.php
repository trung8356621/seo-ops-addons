<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Automation approval tokens — raw token never persisted.
 */
final class AgentAutomationApprovalTokenService
{
    private const TTL_SECONDS = 3600;

    /** @var array<string, array<string, mixed>> */
    private static array $memory = [];

    /**
     * @param  array<string, mixed>  $bind
     * @return array{token: string, hash: string, expires_at: string}
     */
    public function issue(array $bind): array
    {
        $token = 'awautoapr_'.Str::lower((string) Str::ulid());
        $expiresAt = (new \DateTimeImmutable('now'))->modify('+'.self::TTL_SECONDS.' seconds');
        $hash = $this->hashToken($token);
        $payload = array_merge($bind, [
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ]);
        $this->storePut($this->cacheKey($hash), $payload);

        return [
            'token' => $token,
            'hash' => $hash,
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consume(string $rawToken): ?array
    {
        $hash = $this->hashToken($rawToken);
        $payload = $this->storePull($this->cacheKey($hash));
        if ($payload === null) {
            return null;
        }
        $expires = strtotime((string) ($payload['expires_at'] ?? ''));
        if ($expires === false || $expires < time()) {
            return null;
        }

        return $payload;
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function cacheKey(string $hash): string
    {
        return 'agent_auto_approval:'.$hash;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storePut(string $key, array $payload): void
    {
        self::$memory[$key] = $payload;
        try {
            Cache::put($key, $payload, self::TTL_SECONDS);
        } catch (\Throwable) {
            // unit tests without cache
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storePull(string $key): ?array
    {
        $payload = self::$memory[$key] ?? null;
        unset(self::$memory[$key]);
        try {
            $cached = Cache::pull($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
        }

        return is_array($payload) ? $payload : null;
    }
}
