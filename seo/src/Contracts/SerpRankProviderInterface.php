<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Contracts;

use Omnichannel\Addons\Content\DataTransfer\SerpAllintitleResult;
use Omnichannel\Addons\Content\DataTransfer\SerpProviderUsage;
use Omnichannel\Addons\Content\DataTransfer\SerpRankRequest;
use Omnichannel\Addons\Content\DataTransfer\SerpRankResult;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection;

interface SerpRankProviderInterface
{
    public function providerKey(): string;

    public function displayName(): string;

    public function supportsRankCheck(): bool;

    public function supportsAllintitle(): bool;

    public function supportsSearchVolume(): bool;

    public function searchAllintitle(SeoSerpProviderConnection $connection, SerpRankRequest $request): SerpAllintitleResult;

    /**
     * @return array{ok: bool, message: string, usage: SerpProviderUsage|null}
     */
    public function testConnection(SeoSerpProviderConnection $connection): array;

    public function search(SeoSerpProviderConnection $connection, SerpRankRequest $request): SerpRankResult;

    public function getUsageOrCredits(SeoSerpProviderConnection $connection): ?SerpProviderUsage;
}
