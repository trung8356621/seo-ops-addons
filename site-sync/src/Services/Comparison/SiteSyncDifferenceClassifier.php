<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Comparison;

final class SiteSyncDifferenceClassifier
{
    public const EXPECTED = 'expected_difference';

    public const HARMLESS = 'harmless_difference';

    public const NEEDS_REVIEW = 'needs_review';

    public const BLOCKING = 'blocking';

    public const LEGACY_INVALID = 'legacy_data_invalid';

    public const V2_INVALID = 'v2_data_invalid';

    public const OWNERSHIP = 'source_ownership_difference';

    public const PROVIDER_FORMULA = 'provider_formula_difference';

    public const NORMALIZATION = 'normalization_difference';

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{classification: string, reason_code: string}
     */
    public function classify(string $group, string $code, array $ctx = []): array
    {
        return match ($code) {
            'keyword_case_dedupe' => ['classification' => self::EXPECTED, 'reason_code' => $code],
            'workspace_fallback_present' => ['classification' => self::EXPECTED, 'reason_code' => $code],
            'legacy_score_unknown_provider' => ['classification' => self::EXPECTED, 'reason_code' => $code],
            'manual_link_separated' => ['classification' => self::EXPECTED, 'reason_code' => $code],
            'url_normalized' => ['classification' => self::NORMALIZATION, 'reason_code' => $code],
            'provider_score_incomparable' => ['classification' => self::PROVIDER_FORMULA, 'reason_code' => $code],
            'manual_override_preserved' => ['classification' => self::OWNERSHIP, 'reason_code' => $code],
            'missing_in_v2' => [
                'classification' => ($ctx['critical'] ?? false) ? self::BLOCKING : self::NEEDS_REVIEW,
                'reason_code' => $code,
            ],
            'missing_in_legacy' => ['classification' => self::NEEDS_REVIEW, 'reason_code' => $code],
            'manual_overwritten' => ['classification' => self::BLOCKING, 'reason_code' => $code],
            'dead_letter_critical' => ['classification' => self::BLOCKING, 'reason_code' => $code],
            'invalid_legacy_url' => ['classification' => self::LEGACY_INVALID, 'reason_code' => $code],
            'invalid_v2_url' => ['classification' => self::V2_INVALID, 'reason_code' => $code],
            default => ['classification' => self::NEEDS_REVIEW, 'reason_code' => $code !== '' ? $code : 'unclassified'],
        };
    }

    public function isBlocking(string $classification): bool
    {
        return $classification === self::BLOCKING;
    }
}
