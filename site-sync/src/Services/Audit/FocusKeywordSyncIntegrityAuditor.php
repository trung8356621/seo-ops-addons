<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Audit;

use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteSyncKeywordCandidateEvaluator;

/**
 * Pure set-diff audit: WP provider focus → V3 payload → Laravel provider relation.
 * Does not mutate Site Sync importer/exporter.
 */
final class FocusKeywordSyncIntegrityAuditor
{
    public const CLASS_WP_TRULY_MISSING = 'wp_truly_missing';

    public const CLASS_WP_TO_V3_LOSS = 'wp_to_v3_loss';

    public const CLASS_V3_TO_LARAVEL_LOSS = 'v3_to_laravel_loss';

    public const CLASS_RESOLVER_UI = 'resolver_ui';

    public const CLASS_MANUAL_WORKSPACE_EDGE = 'manual_workspace_edge';

    public function __construct(
        private readonly SiteSyncKeywordCandidateEvaluator $candidateEvaluator = new SiteSyncKeywordCandidateEvaluator(),
    ) {}

    /**
     * @param  array<int, list<string>>  $wpFocusByWpId  WP ID → raw provider focus phrases
     * @param  array<int, list<string>>  $v3FocusByWpId  WP ID → phrases in V3 seo.focus_keywords
     * @param  array<int, bool>  $laravelProviderByWpId  WP ID → has provider focus relation
     * @param  array<int, bool>  $laravelEffectiveByWpId  WP ID → has effective focus (any source)
     * @param  list<int>  $eligibleWpIds  SEO-eligible WP IDs present in Laravel inventory
     * @param  list<int>  $missingEffectiveWpIds  Domain missing set (WP-backed only)
     * @return array{
     *   stages: array{
     *     wordpress_provider: int,
     *     v3_payload: int,
     *     laravel_provider_relation: int,
     *     effective_coverage: int
     *   },
     *   set_diffs: array{
     *     wp_minus_v3: list<int>,
     *     v3_minus_laravel_provider: list<int>
     *   },
     *   classification: array<string, int>,
     *   classification_wp_ids: array<string, list<int>>,
     *   candidate_rejections: array<string, int>,
     *   samples: array{
     *     covered: list<array<string, mixed>>,
     *     missing_wp_also_missing: list<array<string, mixed>>,
     *     missing_wp_has_focus: list<array<string, mixed>>
     *   }
     * }
     */
    public function audit(
        array $wpFocusByWpId,
        array $v3FocusByWpId,
        array $laravelProviderByWpId,
        array $laravelEffectiveByWpId,
        array $eligibleWpIds,
        array $missingEffectiveWpIds,
    ): array {
        $eligibleSet = array_fill_keys($eligibleWpIds, true);

        $wpWith = [];
        $v3With = [];
        $laravelProviderWith = [];
        $effectiveWith = [];
        $candidateRejections = [];

        foreach ($eligibleWpIds as $wpId) {
            $wpPhrases = $this->normalizePhrases($wpFocusByWpId[$wpId] ?? []);
            $acceptedWp = [];
            foreach ($wpPhrases as $phrase) {
                $eval = $this->candidateEvaluator->evaluate(
                    $phrase,
                    mb_strtolower($phrase),
                    SiteSyncKeywordCandidateEvaluator::CANDIDATE_PROVIDER,
                );
                if ($eval['eligible']) {
                    $acceptedWp[] = $phrase;
                } else {
                    $reason = (string) ($eval['reason'] ?? 'rejected');
                    $candidateRejections[$reason] = ($candidateRejections[$reason] ?? 0) + 1;
                }
            }
            if ($acceptedWp !== []) {
                $wpWith[$wpId] = true;
            }

            $v3Phrases = $this->normalizePhrases($v3FocusByWpId[$wpId] ?? []);
            if ($v3Phrases !== []) {
                $v3With[$wpId] = true;
            }

            if (($laravelProviderByWpId[$wpId] ?? false) === true) {
                $laravelProviderWith[$wpId] = true;
            }
            if (($laravelEffectiveByWpId[$wpId] ?? false) === true) {
                $effectiveWith[$wpId] = true;
            }
        }

        $wpMinusV3 = [];
        foreach (array_keys($wpWith) as $wpId) {
            if (! isset($v3With[$wpId])) {
                $wpMinusV3[] = $wpId;
            }
        }

        $v3MinusLaravel = [];
        foreach (array_keys($v3With) as $wpId) {
            if (! isset($laravelProviderWith[$wpId])) {
                $v3MinusLaravel[] = $wpId;
            }
        }

        $classification = [
            self::CLASS_WP_TRULY_MISSING => 0,
            self::CLASS_WP_TO_V3_LOSS => 0,
            self::CLASS_V3_TO_LARAVEL_LOSS => 0,
            self::CLASS_RESOLVER_UI => 0,
            self::CLASS_MANUAL_WORKSPACE_EDGE => 0,
        ];
        $classificationIds = [
            self::CLASS_WP_TRULY_MISSING => [],
            self::CLASS_WP_TO_V3_LOSS => [],
            self::CLASS_V3_TO_LARAVEL_LOSS => [],
            self::CLASS_RESOLVER_UI => [],
            self::CLASS_MANUAL_WORKSPACE_EDGE => [],
        ];

        foreach ($missingEffectiveWpIds as $wpId) {
            if (! isset($eligibleSet[$wpId])) {
                continue;
            }
            $class = $this->classifyMissing(
                isset($wpWith[$wpId]),
                isset($v3With[$wpId]),
                isset($laravelProviderWith[$wpId]),
                isset($effectiveWith[$wpId]),
            );
            $classification[$class]++;
            $classificationIds[$class][] = $wpId;
        }

        return [
            'stages' => [
                'wordpress_provider' => count($wpWith),
                'v3_payload' => count($v3With),
                'laravel_provider_relation' => count($laravelProviderWith),
                'effective_coverage' => count($effectiveWith),
            ],
            'set_diffs' => [
                'wp_minus_v3' => $wpMinusV3,
                'v3_minus_laravel_provider' => $v3MinusLaravel,
            ],
            'classification' => $classification,
            'classification_wp_ids' => $classificationIds,
            'candidate_rejections' => $candidateRejections,
            'samples' => $this->buildSamples(
                $eligibleWpIds,
                $missingEffectiveWpIds,
                $wpFocusByWpId,
                $v3FocusByWpId,
                $laravelProviderByWpId,
                $laravelEffectiveByWpId,
                $wpWith,
            ),
        ];
    }

    private function classifyMissing(
        bool $wpHas,
        bool $v3Has,
        bool $laravelProviderHas,
        bool $effectiveHas,
    ): string {
        if ($effectiveHas) {
            return self::CLASS_RESOLVER_UI;
        }
        if (! $wpHas) {
            return self::CLASS_WP_TRULY_MISSING;
        }
        if ($wpHas && ! $v3Has) {
            return self::CLASS_WP_TO_V3_LOSS;
        }
        if ($v3Has && ! $laravelProviderHas) {
            return self::CLASS_V3_TO_LARAVEL_LOSS;
        }
        if ($laravelProviderHas && ! $effectiveHas) {
            return self::CLASS_RESOLVER_UI;
        }

        return self::CLASS_MANUAL_WORKSPACE_EDGE;
    }

    /**
     * @param  list<int>  $eligibleWpIds
     * @param  list<int>  $missingEffectiveWpIds
     * @param  array<int, list<string>>  $wpFocusByWpId
     * @param  array<int, list<string>>  $v3FocusByWpId
     * @param  array<int, bool>  $laravelProviderByWpId
     * @param  array<int, bool>  $laravelEffectiveByWpId
     * @param  array<int, true>  $wpWith
     * @return array{
     *   covered: list<array<string, mixed>>,
     *   missing_wp_also_missing: list<array<string, mixed>>,
     *   missing_wp_has_focus: list<array<string, mixed>>
     * }
     */
    private function buildSamples(
        array $eligibleWpIds,
        array $missingEffectiveWpIds,
        array $wpFocusByWpId,
        array $v3FocusByWpId,
        array $laravelProviderByWpId,
        array $laravelEffectiveByWpId,
        array $wpWith,
    ): array {
        $covered = [];
        foreach ($eligibleWpIds as $wpId) {
            if (($laravelEffectiveByWpId[$wpId] ?? false) !== true) {
                continue;
            }
            $covered[] = $this->sampleRow(
                $wpId,
                $wpFocusByWpId,
                $v3FocusByWpId,
                $laravelProviderByWpId,
                $laravelEffectiveByWpId,
            );
            if (count($covered) >= 5) {
                break;
            }
        }

        $missingAlso = [];
        $missingHas = [];
        foreach ($missingEffectiveWpIds as $wpId) {
            $row = $this->sampleRow(
                $wpId,
                $wpFocusByWpId,
                $v3FocusByWpId,
                $laravelProviderByWpId,
                $laravelEffectiveByWpId,
            );
            if (isset($wpWith[$wpId])) {
                if (count($missingHas) < 5) {
                    $missingHas[] = $row;
                }
            } elseif (count($missingAlso) < 5) {
                $missingAlso[] = $row;
            }
            if (count($missingAlso) >= 5 && count($missingHas) >= 5) {
                break;
            }
        }

        return [
            'covered' => $covered,
            'missing_wp_also_missing' => $missingAlso,
            'missing_wp_has_focus' => $missingHas,
        ];
    }

    /**
     * @param  array<int, list<string>>  $wpFocusByWpId
     * @param  array<int, list<string>>  $v3FocusByWpId
     * @param  array<int, bool>  $laravelProviderByWpId
     * @param  array<int, bool>  $laravelEffectiveByWpId
     * @return array<string, mixed>
     */
    private function sampleRow(
        int $wpId,
        array $wpFocusByWpId,
        array $v3FocusByWpId,
        array $laravelProviderByWpId,
        array $laravelEffectiveByWpId,
    ): array {
        return [
            'wp_id' => $wpId,
            'wp_focus_raw' => $wpFocusByWpId[$wpId] ?? [],
            'v3_focus_keywords' => $v3FocusByWpId[$wpId] ?? [],
            'laravel_provider_relation' => (bool) ($laravelProviderByWpId[$wpId] ?? false),
            'laravel_effective' => (bool) ($laravelEffectiveByWpId[$wpId] ?? false),
        ];
    }

    /**
     * @param  list<string>  $phrases
     * @return list<string>
     */
    private function normalizePhrases(array $phrases): array
    {
        $out = [];
        foreach ($phrases as $phrase) {
            foreach (preg_split('/\s*,\s*/u', trim($phrase)) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
