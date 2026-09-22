<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;

/**
 * SSOT for Prompt Hook default routing policy + optional prompt-level override.
 *
 * Effective (before global FreeOnly): prompt.routing_policy (when set) ?? hook map ?? normal.
 * Global FreeOnly is applied by {@see EffectiveAiRoutingPolicyResolver}.
 */
final class PromptRoutingPolicyResolver
{
    /**
     * @var array<string, AiRoutingPolicy>
     */
    private const HOOK_MAP = [
        'seeding.comment.generate' => AiRoutingPolicy::QuickFree,
    ];

    public function resolve(?SeoPrompt $prompt, ?string $hookKey = null): AiRoutingPolicy
    {
        $override = $this->overrideFromPrompt($prompt);
        if ($override !== null) {
            return $override;
        }

        $hook = trim($hookKey ?? (string) ($prompt?->hook_key ?? ''));
        if ($hook !== '' && isset(self::HOOK_MAP[$hook])) {
            return self::HOOK_MAP[$hook];
        }

        return AiRoutingPolicy::Normal;
    }

    public function hookDefault(?string $hookKey): AiRoutingPolicy
    {
        return $this->resolve(null, $hookKey);
    }

    public function overrideFromPrompt(?SeoPrompt $prompt): ?AiRoutingPolicy
    {
        if ($prompt === null) {
            return null;
        }

        $raw = $prompt->routing_policy ?? null;
        if ($raw === null || trim((string) $raw) === '') {
            // Settings fallback for pre-migration / soft storage.
            $settings = is_array($prompt->settings) ? $prompt->settings : [];
            $raw = $settings['routing_policy'] ?? null;
        }

        return AiRoutingPolicy::tryFromMixed($raw);
    }

    /**
     * @return array<string, string> value => display name
     */
    public function selectablePolicyOptions(): array
    {
        $out = [];
        foreach (AiRoutingPolicy::cases() as $policy) {
            $out[$policy->value] = $policy->displayName();
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function hookMap(): array
    {
        $out = [];
        foreach (self::HOOK_MAP as $hook => $policy) {
            $out[$hook] = $policy->value;
        }

        return $out;
    }
}
