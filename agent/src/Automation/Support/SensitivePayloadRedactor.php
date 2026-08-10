<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Support;

final class SensitivePayloadRedactor
{
    /** @var list<string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'access_key',
        'private_key',
        'cookie',
        'credential',
        'application_password',
        'webhook_secret',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;
            if ($this->isSensitiveKey($keyString)) {
                $out[$keyString] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$keyString] = $this->redact($value);

                continue;
            }

            $out[$keyString] = $value;
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
