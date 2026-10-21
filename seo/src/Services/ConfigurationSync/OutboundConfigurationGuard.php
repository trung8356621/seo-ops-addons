<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

/**
 * Explicit outbound allowlist. Presence in a full-configuration export is not enough to upload.
 */
final class OutboundConfigurationGuard
{
    /** @var list<string> */
    private const ROOT_KEYS = [
        'protocol',
        'request_id',
        'installation_id',
        'service',
        'idempotency_key',
        'client',
        'golden_baseline',
        'current_canonical_hash',
        'diff',
    ];

    /** @var list<string> */
    private const PROMPT_KEYS = [
        'portable_uuid',
        'name',
        'hook_key',
        'change_type',
        'content_fingerprint',
        'changes',
    ];

    /** @var list<string> */
    private const WORKFLOW_KEYS = [
        'portable_uuid',
        'name',
        'change_type',
        'workflow_fingerprint',
        'referenced_prompt_portable_uuids',
        'changes',
    ];

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'api_key',
        'token',
        'access_token',
        'refresh_token',
        'password',
        'secret',
        'client_secret',
        'machine_secret',
        'authorization',
        'private_key',
        'database_url',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertUploadable(array $payload): void
    {
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, self::ROOT_KEYS, true)) {
                throw new ConfigurationSyncException('outbound_rejected', 'Outbound payload contains a field outside the allowlist.');
            }
        }
        $this->assertListKeys($payload['diff']['prompts'] ?? [], self::PROMPT_KEYS, 'prompts');
        $this->assertListKeys($payload['diff']['workflows'] ?? [], self::WORKFLOW_KEYS, 'workflows');
        $settings = $payload['diff']['settings'] ?? [];
        if (is_array($settings) && array_key_exists('connections', $settings['ai'] ?? [])) {
            throw new ConfigurationSyncException('outbound_rejected', 'Connection inventory is not an allowed upstream field.');
        }
        $this->walk($payload);
        $encoded = ConfigSyncCrypto::encodePayload($payload);
        if (strlen($encoded) > 5 * 1024 * 1024) {
            throw new ConfigurationSyncException('payload_too_large', 'Configuration sync payload exceeds 5 MB.', 413);
        }
    }

    /**
     * @param  mixed  $rows
     * @param  list<string>  $allowed
     */
    private function assertListKeys(mixed $rows, array $allowed, string $label): void
    {
        if (! is_array($rows)) {
            throw new ConfigurationSyncException('outbound_rejected', 'Outbound '.$label.' diff must be a list.');
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new ConfigurationSyncException('outbound_rejected', 'Outbound '.$label.' row is invalid.');
            }
            if (trim((string) ($row['portable_uuid'] ?? '')) === '') {
                throw new ConfigurationSyncException('missing_component_identity', 'Outbound '.$label.' row is missing portable_uuid.');
            }
            foreach (array_keys($row) as $key) {
                if (! in_array((string) $key, $allowed, true)) {
                    throw new ConfigurationSyncException('outbound_rejected', 'Outbound '.$label.' field is outside the allowlist.');
                }
            }
        }
    }

    private function walk(mixed $node): void
    {
        if (! is_array($node)) {
            if (is_string($node) && $this->looksLikeSecret($node)) {
                throw new ConfigurationSyncException('credential_detected', 'Outbound payload contains a secret pattern.', 422);
            }

            return;
        }
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                if (is_string($value) && trim($value) !== '') {
                    throw new ConfigurationSyncException('credential_detected', 'Outbound payload contains a forbidden credential field.', 422);
                }
            }
            $this->walk($value);
        }
    }

    private function looksLikeSecret(string $value): bool
    {
        $trimmed = trim($value);

        return preg_match('/^(sk-|sk-or-|sk-ant-|AIza)[A-Za-z0-9_\-]{8,}$/', $trimmed) === 1
            || preg_match('/^Bearer\s+\S{8,}/i', $trimmed) === 1
            || str_contains($trimmed, '-----BEGIN');
    }
}
