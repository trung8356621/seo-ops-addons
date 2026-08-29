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
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::ALL, self::DRAFT, self::PROJECT, self::ARCHIVED];
    }

    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === self::ALL) {
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
            ['value' => self::DRAFT, 'label' => (string) __('seo-content-ai::filament.projects.project_type_draft')],
            ['value' => self::PROJECT, 'label' => (string) __('seo-content-ai::filament.projects.project_type_project')],
            ['value' => self::ARCHIVED, 'label' => (string) __('seo-content-ai::filament.projects.project_type_archived')],
        ];
    }

    /**
     * Apply bucket + optional execution month to a Projects list query.
     * Draft has no execution month — never require month match for Draft rows.
     *
     * @param  Builder<SeoProject>  $query
     * @return Builder<SeoProject>
     */
    public static function apply(Builder $query, string $bucket, ?string $monthDate = null): Builder
    {
        $bucket = self::normalize($bucket);
        $monthDate = is_string($monthDate) && $monthDate !== '' ? $monthDate : null;

        return match ($bucket) {
            self::DRAFT => $query
                ->where('status', SeoProject::STATUS_DRAFT)
                ->whereNull('archived_at'),
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
     * @param  Builder<SeoProject>  $query
     * @return Builder<SeoProject>
     */
    private static function applyAllBucket(Builder $query, ?string $monthDate): Builder
    {
        if ($monthDate === null) {
            return $query;
        }

        // Draft (shared pool) + any project/archived row for the selected execution month.
        return $query->where(function (Builder $builder) use ($monthDate): void {
            $builder
                ->where(function (Builder $draft): void {
                    $draft
                        ->where('status', SeoProject::STATUS_DRAFT)
                        ->whereNull('archived_at');
                })
                ->orWhereDate('month', $monthDate);
        });
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
