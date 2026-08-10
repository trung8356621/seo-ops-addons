<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Contracts;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGlobalContext;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryShotDefinition;

/**
 * AI boundary for Mode 2 Parent/Child — injectable for tests / provider adapters.
 */
interface ProductGalleryParentChildAiPort
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function runPlanner(SeoArticle $article, array $variables): string;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function generateParent(SeoArticle $article, array $variables): ?SeoMedia;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function generateChild(
        SeoArticle $article,
        SeoMedia $parent,
        ProductGalleryShotDefinition $shot,
        ProductGalleryGlobalContext $context,
        array $variables,
    ): ?SeoMedia;
}
