<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Preview confirmation token — TTL 900s, bind payload đầy đủ, consume once.
 */
final class ContentProjectPreviewToken
{
    private const TTL_SECONDS = 900;

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    public function issue(array $fingerprint): string
    {
        $token = 'cpprev_'.Str::lower((string) Str::ulid());
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        Cache::put($this->key($token), [
            'tenant_site_id' => $fingerprint['tenant_site_id'] ?? $fingerprint['site_id'] ?? null,
            'actor_type' => (string) ($fingerprint['actor_type'] ?? ''),
            'actor_id' => $fingerprint['actor_id'] ?? null,
            'action' => (string) ($fingerprint['action'] ?? ''),
            'project_ref' => (string) ($fingerprint['project_ref'] ?? ''),
            'item_refs' => is_array($fingerprint['item_refs'] ?? null) ? $fingerprint['item_refs'] : [],
            'input_hash' => $this->hashInput($fingerprint),
            'state_fingerprint' => $this->hashState($fingerprint),
            'expires_at' => $expiresAt->toIso8601String(),
            'consumed' => false,
        ], self::TTL_SECONDS);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     *
     * @return 'ok'|'invalid'|'expired'|'stale'
     */
    public function validate(string $token, array $fingerprint): string
    {
        $payload = Cache::get($this->key($token));
        if (! is_array($payload)) {
            return 'invalid';
        }

        if (($payload['consumed'] ?? false) === true) {
            return 'invalid';
        }

        $expiresAt = (string) ($payload['expires_at'] ?? '');
        if ($expiresAt !== '' && now()->greaterThan($expiresAt)) {
            return 'expired';
        }

        $expectedState = $this->hashState($fingerprint);
        $storedState = (string) ($payload['state_fingerprint'] ?? '');

        if ($storedState !== '' && ! hash_equals($storedState, $expectedState)) {
            return 'stale';
        }

        $expectedInput = $this->hashInput($fingerprint);
        $storedInput = (string) ($payload['input_hash'] ?? '');

        if ($storedInput !== '' && ! hash_equals($storedInput, $expectedInput)) {
            return 'stale';
        }

        $siteId = (int) ($fingerprint['tenant_site_id'] ?? $fingerprint['site_id'] ?? 0);
        $storedSiteId = (int) ($payload['tenant_site_id'] ?? 0);
        if ($storedSiteId > 0 && $siteId > 0 && $storedSiteId !== $siteId) {
            return 'stale';
        }

        $action = (string) ($fingerprint['action'] ?? '');
        if ($action !== '' && (string) ($payload['action'] ?? '') !== $action) {
            return 'stale';
        }

        $projectRef = (string) ($fingerprint['project_ref'] ?? '');
        if ($projectRef !== '' && (string) ($payload['project_ref'] ?? '') !== $projectRef) {
            return 'stale';
        }

        return 'ok';
    }

    public function consume(string $token): void
    {
        $cacheKey = $this->key($token);
        $payload = Cache::get($cacheKey);
        if (! is_array($payload)) {
            return;
        }

        $payload['consumed'] = true;
        Cache::forget($cacheKey);
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    private function hashInput(array $fingerprint): string
    {
        $input = $fingerprint;
        unset($input['state_fingerprint'], $input['expires_at']);

        return $this->hash($input);
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    private function hashState(array $fingerprint): string
    {
        if (isset($fingerprint['state_fingerprint']) && is_string($fingerprint['state_fingerprint'])) {
            return $fingerprint['state_fingerprint'];
        }

        $state = [
            'project_ref' => $fingerprint['project_ref'] ?? null,
            'item_refs' => $fingerprint['item_refs'] ?? null,
            'action' => $fingerprint['action'] ?? null,
        ];

        return $this->hash($state);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function key(string $token): string
    {
        return 'seo.cp.preview.'.$token;
    }
}
