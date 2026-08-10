<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;

/**
 * Sanitizer tập trung cho business_events + action snapshots + error messages.
 * Case-insensitive key fragment match (không chỉ exact).
 */
final class AutomationSnapshotSanitizer
{
    /** @var list<string> */
    private const EXTRA_FRAGMENTS = [
        'cookie',
        'credential',
        'application_password',
        'app_password',
        'bearer',
        'webhook_secret',
        'access_token',
        'refresh_token',
    ];

    public function __construct(
        private readonly SensitivePayloadRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function sanitize(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return $this->walk($payload);
    }

    public function sanitizeMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        // Strip obvious secret-looking assignments in free text.
        $cleaned = preg_replace(
            '/\b(password|token|secret|api[_-]?key|authorization|cookie|credential)\s*[:=]\s*\S+/i',
            '$1=[redacted]',
            $message,
        );

        return is_string($cleaned) ? $cleaned : $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function walk(array $payload): array
    {
        $base = $this->redactor->redact($payload);
        $out = [];

        foreach ($base as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;
            if ($this->isExtraSensitive($keyString)) {
                $out[$keyString] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$keyString] = $this->walk($value);

                continue;
            }

            if (is_string($value) && $this->looksLikeSecretValue($keyString, $value)) {
                $out[$keyString] = '[redacted]';

                continue;
            }

            $out[$keyString] = $value;
        }

        return $out;
    }

    private function isExtraSensitive(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::EXTRA_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSecretValue(string $key, string $value): bool
    {
        $normalized = strtolower($key);
        if (! str_contains($normalized, 'header') && ! str_contains($normalized, 'auth')) {
            return false;
        }

        return str_starts_with(strtolower(trim($value)), 'bearer ')
            || str_starts_with(strtolower(trim($value)), 'basic ');
    }
}
