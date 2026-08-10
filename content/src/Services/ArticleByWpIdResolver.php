<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Models\Site;

final class ArticleByWpIdResolver
{
    /**
     * @return list<string>
     */
    public function candidateTypes(string $preferredType): array
    {
        $preferredType = $this->normalizeType($preferredType);

        $fallbacks = match ($preferredType) {
            'product' => ['article'],
            'product_category' => ['category'],
            'category' => ['product_category'],
            default => [],
        };

        return array_values(array_unique([$preferredType, ...$fallbacks]));
    }

    public function resolve(Site $site, int $wpId, string $preferredType): ?SeoArticle
    {
        if ($wpId <= 0) {
            return null;
        }

        foreach ($this->candidateTypes($preferredType) as $type) {
            $article = SeoArticle::query()
                ->where('site_id', $site->id)
                ->whereWpPostId($wpId)
                ->where('type', $type)
                ->first();

            if ($article instanceof SeoArticle) {
                return $article;
            }
        }

        return null;
    }

    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'article', 'post', 'page' => 'article',
            'product' => 'product',
            'category' => 'category',
            'product_category', 'product_cat' => 'product_category',
            default => $type !== '' ? $type : 'article',
        };
    }
}
