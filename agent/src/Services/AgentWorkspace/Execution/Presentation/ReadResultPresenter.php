<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation;

/**
 * Shared helpers for compact user-facing read result cards.
 */
final class ReadResultPresenter
{
    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    public static function card(string $title, array $lines): array
    {
        $summary = implode("\n", array_values(array_filter(
            $lines,
            static fn (string $line): bool => $line !== '',
        )));

        return [
            'title' => $title,
            'summary' => $summary,
            'body' => $summary,
            'user_facing' => true,
            'hide_envelope' => true,
            'badges' => [],
            'links' => [],
            'metrics' => [],
            'warnings' => [],
            'suggested_skills' => [],
            'operation_reference' => null,
            'details' => [],
        ];
    }

    /**
     * Strip internal refs from arbitrary scalar/list payloads for chat.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $forbiddenKeys
     * @return array<string, mixed>
     */
    public static function withoutInternalKeys(array $data, array $forbiddenKeys = []): array
    {
        $deny = array_merge([
            'site_ref',
            'tenant_ref',
            'operation_id',
            'operation_ref',
            'execution_ref',
            'capability',
            'capability_key',
            'trace_id',
            'request_id',
            'confirmation_token',
            'confirmation_token_hash',
        ], $forbiddenKeys);

        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || in_array($key, $deny, true)) {
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::withoutInternalKeys($value, $forbiddenKeys);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
