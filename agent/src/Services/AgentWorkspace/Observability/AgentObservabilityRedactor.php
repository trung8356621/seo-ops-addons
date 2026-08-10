<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;

/**
 * Observability redaction — secrets, tokens, oversized content.
 */
final class AgentObservabilityRedactor
{
    private const MAX_STRING = 500;

    private const MAX_DEPTH = 4;

    public function __construct(
        private readonly SensitivePayloadRedactor $base = new SensitivePayloadRedactor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload, int $depth = 0): array
    {
        $base = $this->base->redact($payload);
        $out = [];
        foreach ($base as $key => $value) {
            $keyString = (string) $key;
            if ($this->isBlockedKey($keyString)) {
                $out[$keyString] = '[redacted]';

                continue;
            }
            if (is_array($value)) {
                if ($depth >= self::MAX_DEPTH) {
                    $out[$keyString] = '[truncated]';

                    continue;
                }
                /** @var array<string, mixed> $value */
                $out[$keyString] = $this->redact($value, $depth + 1);

                continue;
            }
            if (is_string($value) && mb_strlen($value) > self::MAX_STRING) {
                $out[$keyString] = mb_substr($value, 0, self::MAX_STRING).'…[truncated]';

                continue;
            }
            $out[$keyString] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @return array<string, string>
     */
    public function filterDimensions(array $dimensions): array
    {
        $out = [];
        foreach ($dimensions as $key => $value) {
            $k = (string) $key;
            if (! in_array($k, AgentObservabilityCatalog::ALLOWED_DIMENSIONS, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $s = substr((string) $value, 0, 64);
                if ($s !== '') {
                    $out[$k] = $s;
                }
            }
        }

        return $out;
    }

    private function isBlockedKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach ([
            'password', 'secret', 'token', 'api_key', 'authorization',
            'prompt', 'raw_prompt', 'hidden_prompt', 'chain_of_thought',
            'cot', 'cookie', 'credential', 'confirmation_token',
        ] as $frag) {
            if (str_contains($lower, $frag)) {
                return true;
            }
        }

        return false;
    }
}
