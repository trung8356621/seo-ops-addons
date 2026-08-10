<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\SeoScoringCalculator;

final class SeoRuleViolationsResolver
{
    /**
     * @return list<string>
     */
    public static function forArticle(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');

        $fromNew = self::readViolationsMeta($article, SeoScoringRulesRegistry::META_KEY_VIOLATIONS);
        if ($fromNew !== null) {
            return SeoScoringRulesRegistry::activeViolations($fromNew);
        }

        // seo_rule_violations is SoT — legacy seo_scoring_details no longer read.
        return [];
    }

    /**
     * @param  list<string>  $violations
     * @return list<string>
     */
    public static function activeViolationsForArticle(SeoArticle $article): array
    {
        return self::forArticle($article);
    }

    public static function scoreForArticle(SeoArticle $article): ?int
    {
        if (! $article->countsTowardSeoScore()) {
            return null;
        }

        $violations = self::forArticle($article);
        if ($violations === [] && $article->seoProfile?->seo_score === null) {
            return null;
        }

        return SeoScoringCalculator::scoreFromViolations($violations);
    }

    /**
     * @return list<string>|null
     */
    private static function readViolationsMeta(SeoArticle $article, string $metaKey): ?array
    {
        $decoded = self::decodeMetaJson($article, $metaKey);
        if ($decoded === null) {
            return null;
        }

        if (self::isViolationList($decoded)) {
            return SeoScoringRulesRegistry::sanitizeViolations($decoded);
        }

        return null;
    }

    private static function isViolationList(mixed $decoded): bool
    {
        if (! is_array($decoded) || $decoded === []) {
            return is_array($decoded);
        }

        return array_is_list($decoded) && is_string($decoded[0] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeMetaJson(SeoArticle $article, string $key): ?array
    {
        /** @var ArticleMeta|null $meta */
        $meta = $article->articleMetas->firstWhere('meta_key', $key);
        if ($meta === null || ! is_string($meta->meta_value) || trim($meta->meta_value) === '') {
            return null;
        }

        $decoded = json_decode($meta->meta_value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
