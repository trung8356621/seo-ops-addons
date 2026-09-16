<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Contracts\AiGeneratedLinkDestinationGate;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;

/**
 * Verifies Gen link destinations against WordPress SoT (no domain+slug fabrication).
 */
final class WordPressAiGeneratedLinkDestinationGate implements AiGeneratedLinkDestinationGate
{
    public function __construct(
        private readonly KeywordLinkTargetResolver $linkTargetResolver,
        private readonly WordPressInternalLinkTargetPolicy $linkTargetPolicy,
    ) {}

    public function isVerifiedDestination(string $href, SeoArticle $article): bool
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return false;
        }

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return false;
        }

        $target = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href, $article);
        if (! $target instanceof SeoArticle) {
            return false;
        }

        return $this->linkTargetPolicy->isEligibleLinkTarget($target);
    }

    public function authoritativePermalink(string $href, SeoArticle $article): ?string
    {
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return null;
        }

        $target = $this->linkTargetResolver->resolveArticleFromUrl($siteId, $href, $article);
        if (! $target instanceof SeoArticle) {
            return null;
        }

        return $this->linkTargetPolicy->resolveAuthoritativePermalink($target);
    }
}
