<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit;

/**
 * Canonical SEO Audit filter snapshot for list / count / Fill / history.
 */
final class SeoAuditSuggestionFilterSet
{
    public const POST_TYPE_MODE_ALL_EXCEPT_PAGE = 'all_except_page';

    public const POST_TYPE_MODE_ALL = 'all';

    public const POST_TYPE_MODE_SPECIFIC = 'specific';

    /**
     * Default safe preset when Draft has never configured filters.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'search' => '',
            'score_max' => null,
            'score_min' => null,
            'issue_keys' => [],
            'suggested_action' => '',
            'language_scope' => 'primary',
            'language' => null,
            'state' => SeoAuditExistingContentSuggestionService::STATE_AVAILABLE,
            'show_dismissed' => false,
            'show_planned' => false,
            'only_with_issues' => true,
            'post_type_mode' => self::POST_TYPE_MODE_ALL_EXCEPT_PAGE,
            'post_type' => '',
            'taxonomy' => '',
            'term_id' => null,
            'exclude_taxonomy_archives' => true,
            'exclude_skip_seo_audit' => true,
            'show_globally_skipped' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function normalize(array $filters): array
    {
        $base = self::defaults();
        $merged = array_merge($base, $filters);

        $scoreMax = $merged['score_max'] ?? null;
        if ($scoreMax === '' || $scoreMax === null) {
            $merged['score_max'] = null;
        } else {
            $merged['score_max'] = (int) $scoreMax;
        }

        $scoreMin = $merged['score_min'] ?? null;
        if ($scoreMin === '' || $scoreMin === null) {
            $merged['score_min'] = null;
        } else {
            $merged['score_min'] = (int) $scoreMin;
        }

        $issueKeys = $merged['issue_keys'] ?? [];
        if (! is_array($issueKeys)) {
            $issueKeys = $issueKeys !== '' && $issueKeys !== null ? [(string) $issueKeys] : [];
        }
        $merged['issue_keys'] = array_values(array_unique(array_filter(
            array_map(static fn (mixed $k): string => trim((string) $k), $issueKeys),
            static fn (string $k): bool => $k !== '',
        )));

        $state = strtolower(trim((string) ($merged['state'] ?? 'available')));
        if (isset($merged['show_dismissed']) || isset($merged['show_planned'])) {
            if ((bool) ($merged['show_dismissed'] ?? false)) {
                $state = 'dismissed';
            } elseif ((bool) ($merged['show_planned'] ?? false)) {
                $state = 'planned';
            } elseif ($state === '') {
                $state = 'available';
            }
        }
        if (! in_array($state, ['available', 'dismissed', 'planned'], true)) {
            $state = 'available';
        }
        $merged['state'] = $state;
        $merged['show_dismissed'] = $state === 'dismissed';
        $merged['show_planned'] = $state === 'planned';

        $scope = strtolower(trim((string) ($merged['language_scope'] ?? 'primary')));
        $merged['language_scope'] = in_array($scope, ['primary', 'all'], true) ? $scope : 'primary';

        $mode = strtolower(trim((string) ($merged['post_type_mode'] ?? self::POST_TYPE_MODE_ALL_EXCEPT_PAGE)));
        if (! in_array($mode, [
            self::POST_TYPE_MODE_ALL_EXCEPT_PAGE,
            self::POST_TYPE_MODE_ALL,
            self::POST_TYPE_MODE_SPECIFIC,
        ], true)) {
            $mode = self::POST_TYPE_MODE_ALL_EXCEPT_PAGE;
        }
        $merged['post_type_mode'] = $mode;
        $merged['post_type'] = strtolower(trim((string) ($merged['post_type'] ?? '')));

        $taxonomy = strtolower(trim((string) ($merged['taxonomy'] ?? '')));
        if ($taxonomy === 'product_cat') {
            $taxonomy = 'product_category';
        }
        $merged['taxonomy'] = in_array($taxonomy, ['category', 'product_category'], true) ? $taxonomy : '';

        $termId = $merged['term_id'] ?? null;
        $merged['term_id'] = ($termId === '' || $termId === null) ? null : max(0, (int) $termId);
        if (($merged['term_id'] ?? 0) <= 0) {
            $merged['term_id'] = null;
        }

        $merged['search'] = trim((string) ($merged['search'] ?? ''));
        $merged['suggested_action'] = strtolower(trim((string) ($merged['suggested_action'] ?? '')));
        $merged['exclude_taxonomy_archives'] = (bool) ($merged['exclude_taxonomy_archives'] ?? true);
        $merged['exclude_skip_seo_audit'] = (bool) ($merged['exclude_skip_seo_audit'] ?? true);
        $merged['show_globally_skipped'] = (bool) ($merged['show_globally_skipped'] ?? false);
        $merged['only_with_issues'] = (bool) ($merged['only_with_issues'] ?? true);

        if ($merged['show_globally_skipped']) {
            $merged['exclude_skip_seo_audit'] = false;
        }

        return $merged;
    }

    /**
     * Compact immutable snapshot for planner_runs.configuration_snapshot.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function snapshot(array $filters, ?string $resolvedPrimaryLanguage = null): array
    {
        $n = self::normalize($filters);

        return [
            'language_scope' => $n['language_scope'],
            'language' => $n['language'] ?? $resolvedPrimaryLanguage,
            'seo_score' => [
                'score_max' => $n['score_max'],
                'score_min' => $n['score_min'],
            ],
            'post_type_mode' => $n['post_type_mode'],
            'post_types' => $n['post_type'] !== '' ? [$n['post_type']] : [],
            'taxonomy' => $n['taxonomy'],
            'term_id' => $n['term_id'],
            'issues' => $n['issue_keys'],
            'action' => $n['suggested_action'],
            'state' => $n['state'],
            'exclude_taxonomy_archives' => $n['exclude_taxonomy_archives'],
            'exclude_skip_seo_audit' => $n['exclude_skip_seo_audit'],
            'search' => $n['search'],
            'only_with_issues' => $n['only_with_issues'],
        ];
    }

    /**
     * Restore Livewire / Fill filters from a historical snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function fromSnapshot(array $snapshot): array
    {
        $score = is_array($snapshot['seo_score'] ?? null) ? $snapshot['seo_score'] : [];
        $postTypes = is_array($snapshot['post_types'] ?? null) ? $snapshot['post_types'] : [];
        $firstPostType = isset($postTypes[0]) ? (string) $postTypes[0] : '';

        $mode = (string) ($snapshot['post_type_mode'] ?? '');
        if ($mode === '' && $firstPostType !== '') {
            $mode = self::POST_TYPE_MODE_SPECIFIC;
        }
        if ($mode === '') {
            $mode = self::POST_TYPE_MODE_ALL_EXCEPT_PAGE;
        }

        return self::normalize([
            'language_scope' => $snapshot['language_scope'] ?? 'primary',
            'language' => $snapshot['language'] ?? null,
            'score_max' => $score['score_max'] ?? null,
            'score_min' => $score['score_min'] ?? null,
            'issue_keys' => $snapshot['issues'] ?? [],
            'suggested_action' => $snapshot['action'] ?? '',
            'state' => $snapshot['state'] ?? 'available',
            'post_type_mode' => $mode,
            'post_type' => $firstPostType !== '' ? $firstPostType : (string) ($snapshot['post_type'] ?? ''),
            'taxonomy' => $snapshot['taxonomy'] ?? '',
            'term_id' => $snapshot['term_id'] ?? null,
            'exclude_taxonomy_archives' => $snapshot['exclude_taxonomy_archives'] ?? true,
            'exclude_skip_seo_audit' => $snapshot['exclude_skip_seo_audit'] ?? true,
            'search' => $snapshot['search'] ?? '',
            'only_with_issues' => $snapshot['only_with_issues'] ?? true,
        ]);
    }
}
