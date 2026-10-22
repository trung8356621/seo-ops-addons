<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Decide which Widget fields AI Auto may fill.
 * Identity / verified URL fields must never be AI-invented.
 */
final class AiAutoFieldPolicy
{
    /** @var list<string> */
    private const FORBIDDEN_KEY_HINTS = [
        'account', 'identity', 'platform_account', 'session', 'cookie',
        'password', 'token', 'credential', 'group_url', 'page_url',
        'profile_url', 'verified',
    ];

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array{key: string, label: string, input_type: string, ai_policy: string, reason: string}>
     */
    public function plan(array $fields): array
    {
        $plan = [];
        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            $policy = strtolower((string) ($field['ai_policy'] ?? 'eligible'));
            $reason = 'schema';
            if ($policy === 'eligible' && $this->looksIdentityOrVerifiedUrl($field)) {
                $policy = 'manual_only';
                $reason = 'identity_or_verified_url';
            }
            $plan[] = [
                'key' => $key,
                'label' => (string) ($field['label'] ?? $key),
                'input_type' => (string) ($field['input_type'] ?? 'text'),
                'ai_policy' => $policy,
                'reason' => $reason,
            ];
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public function mayAcceptAiValue(array $field): bool
    {
        $policy = strtolower((string) ($field['ai_policy'] ?? 'eligible'));
        if (in_array($policy, ['forbidden', 'manual_only'], true)) {
            return false;
        }
        if ($this->looksIdentityOrVerifiedUrl($field)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function looksIdentityOrVerifiedUrl(array $field): bool
    {
        $key = strtolower((string) ($field['key'] ?? ''));
        $binding = strtolower((string) (($field['binding']['variable_key'] ?? '') ?: ''));
        $type = strtolower((string) ($field['input_type'] ?? ''));
        $haystack = $key.' '.$binding;

        foreach (self::FORBIDDEN_KEY_HINTS as $hint) {
            if (str_contains($haystack, $hint)) {
                return true;
            }
        }

        // Bare URL fields that bind to group/page destinations stay manual unless explicitly eligible.
        if ($type === 'url' && (str_contains($haystack, 'group') || str_contains($haystack, 'page') || str_contains($haystack, 'target'))) {
            return true;
        }

        return false;
    }
}
