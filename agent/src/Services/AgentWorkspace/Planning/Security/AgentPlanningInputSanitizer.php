<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security;

final class AgentPlanningInputSanitizer
{
    private const FORBIDDEN_KEYS = [
        'api_key', 'token', 'authorization', 'password', 'secret',
        'confirmation_token', 'idempotency_key', 'raw_prompt',
        'provider_credentials', 'database_password',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        return $this->walk($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function walk(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $k = is_string($key) ? $key : (string) $key;
            if (in_array(mb_strtolower($k), self::FORBIDDEN_KEYS, true)) {
                $out[$k] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$k] = $this->walk($value);
                continue;
            }
            $out[$k] = $value;
        }

        return $out;
    }
}
