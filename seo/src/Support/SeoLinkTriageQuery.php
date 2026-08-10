<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class SeoLinkTriageQuery
{
    private static ?bool $supportsHttpAuditColumns = null;

    public static function supportsHttpAuditColumns(): bool
    {
        if (self::$supportsHttpAuditColumns === null) {
            $connection = (new SeoLinkMap)->getConnectionName();

            self::$supportsHttpAuditColumns = Schema::connection($connection)
                ->hasColumn('seo_link_maps', 'last_http_status');
        }

        return self::$supportsHttpAuditColumns;
    }

    /**
     * @param  Builder<SeoLinkMap>  $query
     * @return Builder<SeoLinkMap>
     */
    public static function applyIssuesScope(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', SeoLinkMapStatus::Ignored)
            ->where(function (Builder $issuesQuery): void {
                $issuesQuery
                    ->where(function (Builder $brokenQuery): void {
                        static::applyBrokenScope($brokenQuery);
                    })
                    ->orWhere(function (Builder $weakQuery): void {
                        static::applyWeakContextScope($weakQuery);
                    });
            });
    }

    /**
     * @param  Builder<SeoLinkMap>  $query
     * @return Builder<SeoLinkMap>
     */
    public static function applyBrokenScope(Builder $query): Builder
    {
        return $query->where(function (Builder $brokenQuery): void {
            $brokenQuery->where('status', SeoLinkMapStatus::Broken);

            if (self::supportsHttpAuditColumns()) {
                $brokenQuery->orWhere(function (Builder $httpBrokenQuery): void {
                    $httpBrokenQuery
                        ->whereNotNull('last_http_status')
                        ->where('last_http_status', '>=', 400);
                });
            }
        });
    }

    /**
     * @param  Builder<SeoLinkMap>  $query
     * @return Builder<SeoLinkMap>
     */
    public static function applyWeakContextScope(Builder $query): Builder
    {
        return $query->where(function (Builder $weakContextQuery): void {
            $weakContextQuery
                ->whereRaw(AnchorTextAuditCriteria::contextBeforeLengthSql().' < 3')
                ->orWhereRaw(AnchorTextAuditCriteria::contextAfterLengthSql().' < 3');
        });
    }

    /**
     * @param  Builder<SeoLinkMap>  $query
     * @return Builder<SeoLinkMap>
     */
    public static function applyExternalScope(Builder $query): Builder
    {
        return $query->where('link_type', SeoLinkMapType::External);
    }

    public static function hasWeakContext(SeoLinkMap $map): bool
    {
        return mb_strlen(trim((string) ($map->context_before ?? ''))) < 3
            || mb_strlen(trim((string) ($map->context_after ?? ''))) < 3;
    }

    public static function isBrokenNetwork(?int $httpStatus, SeoLinkMapStatus $status): bool
    {
        return SeoLinkMapNetworkStatusPresenter::isBrokenNetwork($httpStatus, $status);
    }

    /**
     * @return array{all_issues: int, broken: int, weak_context: int, external: int}
     */
    public static function countTabs(Builder $baseQuery): array
    {
        $issuesBase = self::applyIssuesScope(clone $baseQuery);

        return [
            'all_issues' => (clone $issuesBase)->count(),
            'broken' => self::applyBrokenScope(clone $issuesBase)->count(),
            'weak_context' => self::applyWeakContextScope(clone $issuesBase)->count(),
            'external' => self::applyExternalScope(clone $issuesBase)->count(),
        ];
    }
}
