<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductGallery;

use Omnichannel\Addons\Seo\Contracts\ProductGalleryParentChildAiPort;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryGlobalContext;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryShotDefinition;

/**
 * Scaffold default — no live provider call. Production adapter replaces this binding.
 */
final class NullProductGalleryParentChildAiPort implements ProductGalleryParentChildAiPort
{
    public function runPlanner(SeoArticle $article, array $variables): string
    {
        throw new \RuntimeException('Mode 2 planner AI port not bound — scaffold only.');
    }

    public function generateParent(SeoArticle $article, array $variables): ?SeoMedia
    {
        throw new \RuntimeException('Mode 2 parent AI port not bound — scaffold only.');
    }

    public function generateChild(
        SeoArticle $article,
        SeoMedia $parent,
        ProductGalleryShotDefinition $shot,
        ProductGalleryGlobalContext $context,
        array $variables,
    ): ?SeoMedia {
        throw new \RuntimeException('Mode 2 child AI port not bound — scaffold only.');
    }
}
