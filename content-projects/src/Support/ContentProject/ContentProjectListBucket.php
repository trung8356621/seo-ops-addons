<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Illuminate\Database\Eloquent\Builder;

/**
 * High-level Projects list buckets (not raw execution lifecycle statuses).
 */
final class ContentProjectListBucket
{
    public const ALL = 'all';

    public const DRAFT = 'draft';

    public const PROJECT = 'project';

    public const ARCHIVED = 'archived';

    /**
     * List-page bucket values exposed in UI / URL normalization.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::ALL, self::PROJECT, self::ARCHIVED];
    }

    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === self::ALL) {
            return self::ALL;
        }

        // Legacy list filters — Draft belongs in Project Planner, never on /seo/content-projects.
        if ($value === self::DRAFT || $value === SeoProject::STATUS_DRAFT) {
            return self::ALL;
        }

        // Legacy raw-status query params → nearest bucket.
        if (in_array($value, [
            SeoProject::STATUS_PENDING,
            SeoProject::STATUS_MANUAL,
            SeoProject::STATUS_RUNNING,
            SeoProject::STATUS_COMPLETED,
            SeoProject::STATUS_PAUSED,
            SeoProject::STATUS_APPROVED,
        ], true)) {
            return self::PROJECT;
        }

        return in_array($value, self::values(), true) ? $value : self::ALL;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function selectOptions(): array
    {
        return [
            ['value' => self::ALL, 'label' => (string) __('seo-content-ai::filament.projects.project_type_all')],
            ['value' => self::PROJECT, 'label' => (string) __('seo-content-ai::filament.projects.project_type_project')],
            ['value' => self::ARCHIVED, 'label' => (string) __('seo-content-ai::filament.projects.project_type_archived')],
        ];
    }

    /**
     * Apply bucket + optional execution month to a Projects list query.
     * Shared Planning Draft never appears on this list — use Project Planner instead.
     *
     * @param  Builder<SeoProject>  $query
     * @return Builder<SeoProject>
     */
    public static function apply(Builder $query, string $bucket, ?string $monthDate = null): Builder
    {
        $bucket = self::normalize($bucket);
        $monthDate = is_string($monthDate) && $monthDate !== '' ? $monthDate : null;

        return match ($bucket) {
            self::PROJECT => self::applyExecutionMonth(
                $query
                    ->whereNull('archived_at')
                    ->where('status', '!=', SeoProject::STATUS_DRAFT),
                $monthDate,
            ),
            self::ARCHIVED => self::applyExecutionMonth(
                $query->whereNotNull('archived_at'),
                $monthDate,
            ),
            default => self::applyAllBucket($query, $monthDate),
        };
    }

    /**
     * All = non-draft execution + archived projects for the selected month.
     *
     * @param  Builder<SeoProject>  $query
     * @return Builder<SeoProject>
     */
    private static function applyAllBucket(Builder $query, ?string $monthDate): Builder
    {
        $query->where('status', '!=', SeoProject::STATUS_DRAFT);

        return self::applyExecutionMonth($query, $monthDate);
    }

    /**
     * @param  Builder<SeoProject>  $query
     * @return Builder<SeoProject>
     */
    private static function applyExecutionMonth(Builder $query, ?string $monthDate): Builder
    {
        if ($monthDate === null) {
            return $query;
        }

        return $query->whereDate('month', $monthDate);
    }
}
