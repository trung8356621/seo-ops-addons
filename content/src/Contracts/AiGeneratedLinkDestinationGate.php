<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Contracts;

use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Gate for Gen-time link destinations: verified authoritative URL vs placeholder.
 */
interface AiGeneratedLinkDestinationGate
{
    public function isVerifiedDestination(string $href, SeoArticle $article): bool;

    public function authoritativePermalink(string $href, SeoArticle $article): ?string;
}
