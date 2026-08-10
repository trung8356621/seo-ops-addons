<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Illuminate\Database\Eloquent\Builder;

final class AnchorTextAuditCriteria
{
    public static function anchorWordCountSql(): string
    {
        return '(CHAR_LENGTH(TRIM(anchor_text)) - CHAR_LENGTH(REPLACE(TRIM(anchor_text), " ", "")) + 1)';
    }

    public static function contextBeforeLengthSql(): string
    {
        return 'CHAR_LENGTH(TRIM(COALESCE(context_before, "")))';
    }

    public static function contextAfterLengthSql(): string
    {
        return 'CHAR_LENGTH(TRIM(COALESCE(context_after, "")))';
    }

    /**
     * @param  Builder<SeoLinkMap>  $query
     * @return Builder<SeoLinkMap>
     */
    public static function applyNeedsAuditScope(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', SeoLinkMapStatus::Ignored)
            ->where(function (Builder $needsAuditQuery): void {
                $needsAuditQuery
                    ->whereRaw(self::anchorWordCountSql().' > 7')
                    ->orWhereRaw(self::contextBeforeLengthSql().' < 3')
                    ->orWhereRaw(self::contextAfterLengthSql().' < 3');
            });
    }

    /**
     * @param  Builder<SeoLinkMap>  $query
     * @return Builder<SeoLinkMap>
     */
    public static function applyLongAnchorFilter(Builder $query): Builder
    {
        return $query->whereRaw(self::anchorWordCountSql().' > 7');
    }

    /**
     * @param  Builder<SeoLinkMap>  $query
     * @return Builder<SeoLinkMap>
     */
    public static function applyWeakContextFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $weakContextQuery): void {
            $weakContextQuery
                ->whereRaw(self::contextBeforeLengthSql().' < 3')
                ->orWhereRaw(self::contextAfterLengthSql().' < 3');
        });
    }

    public static function mapMeetsNeedsAuditCriteria(SeoLinkMap $map): bool
    {
        if ($map->status === SeoLinkMapStatus::Ignored) {
            return false;
        }

        $anchorWords = self::countWords((string) $map->anchor_text);
        if ($anchorWords > 7) {
            return true;
        }

        if (mb_strlen(trim((string) ($map->context_before ?? ''))) < 3) {
            return true;
        }

        return mb_strlen(trim((string) ($map->context_after ?? ''))) < 3;
    }

    public static function countWords(string $phrase): int
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $phrase) ?: [];

        return count(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
