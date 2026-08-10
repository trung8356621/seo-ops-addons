<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

/**
 * Evaluate promotion readiness — never auto-flips mode or stable version.
 */
final class PromptHookPromotionGate
{
    public function __construct(
        private readonly PromptHookShadowParityRecorder $parity = new PromptHookShadowParityRecorder,
        private readonly PromptHookMigrationFlags $flags = new PromptHookMigrationFlags,
        private readonly PromptHookPromotionThresholds $thresholds = new PromptHookPromotionThresholds,
        private readonly PromptHookModeTransitionPolicy $transitions = new PromptHookModeTransitionPolicy,
        private readonly PromptHookRollbackPolicy $rollback = new PromptHookRollbackPolicy,
    ) {}

    public function minSamplesFor(string $hookKey): int
    {
        return $this->thresholds->forHook($hookKey);
    }

    /** @deprecated use minSamplesFor */
    public function minSamples(): int
    {
        return $this->thresholds->forHook('article.outline.generate');
    }

    /**
     * @param  array{
     *   definition_valid?: bool,
     *   version_pinned?: bool,
     *   experimental_allowed?: bool,
     *   provider_capability_ok?: bool,
     *   unexplained_parity_mismatch?: bool,
     *   mismatch_explained?: bool,
     *   schema_failure?: bool,
     *   marker_leak?: bool,
     *   unexpected_locale?: bool,
     *   provider_exception_increase?: bool,
     *   cost_token_anomaly?: bool,
     *   prompt_result_attachment_mismatch?: bool,
     *   duplicate_ai_call?: bool,
     *   domain_side_effect_mismatch?: bool,
     *   provider_model_mapping_mismatch?: bool,
     *   rollback_verified?: bool,
     *   sample_count?: int,
     *   from_mode?: string,
     *   to_mode?: string
     * }  $signals
     * @return array{allowed: bool, blockers: list<string>, samples: int, threshold: int, report: array<string, mixed>}
     */
    public function evaluate(string $hookKey, string $version, array $signals = []): array
    {
        $blockers = [];
        $report = $this->parity->reportFor($hookKey, $version);
        $samples = $signals['sample_count'] ?? $report['sample_count'];
        $threshold = $this->minSamplesFor($hookKey);

        if (($signals['definition_valid'] ?? true) === false) {
            $blockers[] = 'definition_invalid';
        }

        if (($signals['version_pinned'] ?? true) === false || trim($version) === '' || $version === 'latest') {
            $blockers[] = 'version_unpinned';
        }

        if (($signals['experimental_allowed'] ?? $this->flags->experimentalAllowed()) === false) {
            $blockers[] = 'experimental_not_allowed';
        }

        if (($signals['provider_capability_ok'] ?? true) === false) {
            $blockers[] = 'provider_capability_mismatch';
        }

        $hasMismatch = ($report['mismatch_count'] ?? 0) > 0
            || ($signals['unexplained_parity_mismatch'] ?? false);
        if ($hasMismatch && ! ($signals['mismatch_explained'] ?? false)) {
            $blockers[] = 'unexplained_parity_mismatch';
        }

        if (($signals['schema_failure'] ?? false) || ($report['schema_failure_count'] ?? 0) > 0) {
            $blockers[] = 'schema_failure';
        }

        if (($signals['marker_leak'] ?? false) || ($report['marker_leak_count'] ?? 0) > 0) {
            $blockers[] = 'marker_leak';
        }

        if (($signals['unexpected_locale'] ?? false) || ($report['locale_failure_count'] ?? 0) > 0) {
            $blockers[] = 'unexpected_locale';
        }

        if (($signals['provider_exception_increase'] ?? false) || ($report['exception_count'] ?? 0) > 0) {
            $blockers[] = 'provider_exception_increase';
        }

        if (($signals['cost_token_anomaly'] ?? false) || ($report['token_cost_anomaly_count'] ?? 0) > 0) {
            $blockers[] = 'cost_token_anomaly';
        }

        if ($samples < $threshold) {
            $blockers[] = 'missing_sample';
        }

        if (($signals['prompt_result_attachment_mismatch'] ?? false)
            || ($report['prompt_result_linkage_mismatch_count'] ?? 0) > 0) {
            $blockers[] = 'prompt_result_attachment_mismatch';
        }

        if (($signals['duplicate_ai_call'] ?? false) || ($report['duplicate_ai_call_count'] ?? 0) > 0) {
            $blockers[] = 'duplicate_ai_call';
        }

        if (($signals['domain_side_effect_mismatch'] ?? false)
            || ($report['domain_side_effect_mismatch_count'] ?? 0) > 0) {
            $blockers[] = 'domain_side_effect_mismatch';
        }

        if (($signals['provider_model_mapping_mismatch'] ?? false)
            || ($report['provider_mapping_failure_count'] ?? 0) > 0) {
            $blockers[] = 'provider_model_mapping_mismatch';
        }

        if (array_key_exists('rollback_verified', $signals) && $signals['rollback_verified'] === false) {
            $blockers[] = 'rollback_not_verified';
        }

        $from = isset($signals['from_mode'])
            ? PromptHookRuntimeMode::fromConfig((string) $signals['from_mode'])
            : PromptHookRuntimeMode::Shadow;
        $to = isset($signals['to_mode'])
            ? PromptHookRuntimeMode::fromConfig((string) $signals['to_mode'])
            : PromptHookRuntimeMode::Hook;
        if (! $this->transitions->allows($from, $to)) {
            $blockers[] = 'mode_transition_forbidden';
        }

        if ($this->transitions->allowsAutomaticStableVersionPromotion()) {
            $blockers[] = 'automatic_stable_promotion_enabled';
        }

        if ($this->rollback->allowsSilentLegacyFallbackAfterProviderCall()) {
            $blockers[] = 'silent_fallback_enabled';
        }

        return [
            'allowed' => $blockers === [],
            'blockers' => $blockers,
            'samples' => $samples,
            'threshold' => $threshold,
            'report' => $report,
        ];
    }
}
