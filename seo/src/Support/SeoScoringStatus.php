<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;

final class SeoScoringStatus
{
    public const META_KEY_STATUS = 'seo_scoring_status';

    public const META_KEY_FINGERPRINT = 'seo_scoring_fingerprint';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public static function readStatus(SeoArticle $article): ?string
    {
        $value = self::readMetaString($article, self::META_KEY_STATUS);

        if ($value === null || $value === '') {
            return null;
        }

        return in_array($value, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ], true) ? $value : null;
    }

    public static function hasBeenAnalyzed(SeoArticle $article): bool
    {
        if (! $article->countsTowardSeoScore()) {
            return true;
        }

        $status = self::readStatus($article);

        if (in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_FAILED], true)) {
            return false;
        }

        if ($status === self::STATUS_COMPLETED || self::violationsMetaExists($article)) {
            return true;
        }

        return $article->seoProfile?->seo_score !== null;
    }

    public static function needsScoring(SeoArticle $article): bool
    {
        if (! $article->countsTowardSeoScore()) {
            return false;
        }

        $status = self::readStatus($article);

        if (in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            return false;
        }

        if ($status === self::STATUS_FAILED) {
            return true;
        }

        return ! self::hasBeenAnalyzed($article);
    }

    public static function writeStatus(SeoArticle $article, string $status): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY_STATUS],
            ['meta_value' => $status],
        );
    }

    public static function writeFingerprint(SeoArticle $article, string $fingerprint): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY_FINGERPRINT],
            ['meta_value' => $fingerprint],
        );
    }

    public static function readFingerprint(SeoArticle $article): ?string
    {
        return self::readMetaString($article, self::META_KEY_FINGERPRINT);
    }

    private static function violationsMetaExists(SeoArticle $article): bool
    {
        $article->loadMissing('articleMetas');

        /** @var ArticleMeta|null $meta */
        $meta = $article->articleMetas->firstWhere('meta_key', SeoScoringRulesRegistry::META_KEY_VIOLATIONS);

        return $meta !== null && is_string($meta->meta_value) && trim($meta->meta_value) !== '';
    }

    private static function readMetaString(SeoArticle $article, string $metaKey): ?string
    {
        $article->loadMissing('articleMetas');

        /** @var ArticleMeta|null $meta */
        $meta = $article->articleMetas->firstWhere('meta_key', $metaKey);
        if ($meta === null || ! is_string($meta->meta_value)) {
            return null;
        }

        $trimmed = trim($meta->meta_value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
