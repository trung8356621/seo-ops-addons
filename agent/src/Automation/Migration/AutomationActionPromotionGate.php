<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Migration;

/**
 * Gate trước khi chuyển caller sang mode=action.
 */
final class AutomationActionPromotionGate
{
    public function __construct(
        private readonly AutomationParitySampleRecorder $samples,
    ) {}

    public function minSamples(): int
    {
        return max(1, (int) config('seo-content-ai.automation_migration_min_parity_samples', 20));
    }

    /**
     * @param  array{
     *   unexplained_mismatch?: bool,
     *   unexplained_duplicate?: bool,
     *   missing_link?: bool,
     *   wp_outbound?: bool,
     *   new_exception?: bool
     * }  $signals
     * @return array{allowed: bool, reasons: list<string>, samples: array{match: int, mismatch: int, samples: int}}
     */
    public function evaluate(string $callerKey, array $signals = []): array
    {
        $stats = $this->samples->forCaller($callerKey);
        $reasons = [];

        if ($stats['samples'] < $this->minSamples()) {
            $reasons[] = 'insufficient_parity_samples';
        }

        if ($stats['mismatch'] > 0 && ($signals['unexplained_mismatch'] ?? true)) {
            // Mismatch tồn tại và chưa được đánh dấu đã giải thích.
            if (! ($signals['mismatch_explained'] ?? false)) {
                $reasons[] = 'unexplained_parity_mismatch';
            }
        }

        if ($signals['unexplained_duplicate'] ?? false) {
            $reasons[] = 'unexplained_duplicate';
        }

        if ($signals['missing_link'] ?? false) {
            $reasons[] = 'missing_link';
        }

        if ($signals['wp_outbound'] ?? false) {
            $reasons[] = 'wp_outbound_detected';
        }

        if ($signals['new_exception'] ?? false) {
            $reasons[] = 'new_exception';
        }

        return [
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'samples' => $stats,
        ];
    }
}
