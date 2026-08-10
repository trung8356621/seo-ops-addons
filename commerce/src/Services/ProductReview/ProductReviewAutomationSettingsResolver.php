<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRuleAction;

/**
 * Resolve product-review.create settings from enabled Automation Rule actions.
 * Manual sync / editor API use same target_count as automation when not overridden.
 */
final class ProductReviewAutomationSettingsResolver
{
    private const PREFERRED_RULE_CODE = 'sync-article-to-wordpress';

    /**
     * @param  array<string, mixed>  $overrides  Explicit caller values win (automation action settings, request body).
     * @return array{
     *     enabled: bool,
     *     target_count: int,
     *     block_if_real_reviews_exist: bool,
     *     retry_failed: bool
     * }
     */
    public function resolve(array $overrides = []): array
    {
        $fromRule = $this->fromAutomationRules();
        $merged = array_merge($fromRule, $this->meaningfulOverrides($overrides));

        return [
            'enabled' => ($merged['enabled'] ?? true) !== false,
            'target_count' => max(0, min(50, (int) (
                $merged['target_count'] ?? ProductReviewCreationPolicy::DEFAULT_TARGET_COUNT
            ))),
            'block_if_real_reviews_exist' => ($merged['block_if_real_reviews_exist'] ?? true) !== false,
            'retry_failed' => ($merged['retry_failed'] ?? true) !== false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromAutomationRules(): array
    {
        try {
            $actions = AutomationRuleAction::query()
                ->where('action_code', AutomationActionCode::ProductReviewCreate->value)
                ->where('is_enabled', true)
                ->whereHas('rule', fn ($query) => $query->where('is_enabled', true))
                ->with('rule')
                ->get();

            $preferred = $actions->first(
                fn (AutomationRuleAction $action): bool => (string) ($action->rule?->code ?? '') === self::PREFERRED_RULE_CODE,
            );

            if ($preferred instanceof AutomationRuleAction) {
                $settings = is_array($preferred->settings) ? $preferred->settings : [];
                if ($settings !== []) {
                    return $settings;
                }
            }

            foreach ($actions as $action) {
                $settings = is_array($action->settings) ? $action->settings : [];
                if ($settings !== []) {
                    return $settings;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function meaningfulOverrides(array $overrides): array
    {
        $out = [];
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
