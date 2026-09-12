<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Canonical predicate for the imported global Legacy archive.
 *
 * Detect via project.meta.import_source (never project name / hard-coded IDs).
 * This archive is pinned UI only — excluded from monthly workload/export/restore.
 */
final class ContentProjectGlobalLegacyArchive
{
    public const META_IMPORT_SOURCE = 'seo_content_archive_items';

    public static function isGlobalLegacyArchive(SeoProject|SeoProjectArchive|null $model): bool
    {
        if ($model instanceof SeoProject) {
            return self::isProject($model);
        }

        if ($model instanceof SeoProjectArchive) {
            $snapshot = is_array($model->summary_snapshot) ? $model->summary_snapshot : [];
            if (($snapshot['import_source'] ?? null) === self::META_IMPORT_SOURCE) {
                return true;
            }

            $project = $model->relationLoaded('project')
                ? $model->project
                : $model->project()->first();

            return self::isProject($project instanceof SeoProject ? $project : null);
        }

        return false;
    }

    /** Alias preferred by some call sites. */
    public static function isLegacyImportedArchive(SeoProject|SeoProjectArchive|null $model): bool
    {
        return self::isGlobalLegacyArchive($model);
    }

    public static function isProject(?SeoProject $project): bool
    {
        if (! $project instanceof SeoProject) {
            return false;
        }

        $meta = is_array($project->meta) ? $project->meta : [];

        return ($meta['import_source'] ?? null) === self::META_IMPORT_SOURCE;
    }

    public static function badgeLabel(): string
    {
        return (string) __('seo-content-ai::filament.projects.archive_legacy_badge');
    }

    public static function monthLabel(): string
    {
        return (string) __('seo-content-ai::filament.projects.archive_legacy_not_monthly');
    }

    /**
     * Archive list: match month/year OR include global Legacy (once).
     *
     * @param  Builder<SeoProjectArchive>  $query
     * @return Builder<SeoProjectArchive>
     */
    public static function applyMonthYearOrGlobal(Builder $query, int $month, int $year): Builder
    {
        if ($month <= 0 && $year <= 0) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($month, $year): void {
            $builder->where(function (Builder $monthly) use ($month, $year): void {
                if ($month > 0) {
                    $monthly->where('project_month', $month);
                }
                if ($year > 0) {
                    $monthly->where('project_year', $year);
                }
            })->orWhere(function (Builder $global): void {
                self::whereArchiveIsGlobalLegacy($global);
            });
        });
    }

    /**
     * @param  Builder<SeoProjectArchive>  $query
     */
    public static function whereArchiveIsGlobalLegacy(Builder $query): void
    {
        $query->where(function (Builder $builder): void {
            $builder
                ->where('summary_snapshot->import_source', self::META_IMPORT_SOURCE)
                ->orWhereHas('project', static function (Builder $projectQuery): void {
                    $projectQuery->where('meta->import_source', self::META_IMPORT_SOURCE);
                });
        });
    }

    /**
     * Pin global Legacy rows above normal archived_at ordering.
     *
     * @param  Builder<SeoProjectArchive>  $query
     * @return Builder<SeoProjectArchive>
     */
    public static function orderPinnedFirst(Builder $query): Builder
    {
        $archivesTable = $query->getModel()->getTable();

        return $query
            ->orderByRaw(
                'CASE WHEN EXISTS (
                    SELECT 1 FROM seo_projects AS glp
                    WHERE glp.id = '.$archivesTable.'.project_id
                      AND JSON_UNQUOTE(JSON_EXTRACT(glp.meta, \'$.import_source\')) = ?
                ) OR JSON_UNQUOTE(JSON_EXTRACT('.$archivesTable.'.summary_snapshot, \'$.import_source\')) = ?
                THEN 0 ELSE 1 END',
                [self::META_IMPORT_SOURCE, self::META_IMPORT_SOURCE],
            )
            ->orderByDesc('archived_at')
            ->orderByDesc('id');
    }

    /**
     * Exclude imported Legacy from monthly execution workload (tasks join projects as $alias).
     *
     * @param  QueryBuilder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public static function excludeFromProjectAlias(QueryBuilder|Builder $query, string $alias = 'p'): void
    {
        $query->where(function ($builder) use ($alias): void {
            $builder
                ->whereNull("{$alias}.meta")
                ->orWhereRaw(
                    "COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$alias}.meta, '$.import_source')), '') <> ?",
                    [self::META_IMPORT_SOURCE],
                );
        });
    }
}
