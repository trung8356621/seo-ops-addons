<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Navigation\AgentInternalLinkResolver;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\Seo\Support\DomainContextResolver;

final class AgentTopicInternalLinkResolver implements AgentInternalLinkResolver
{
    public function __construct(private readonly DomainContextResolver $domainContext) {}

    public function resolve(string $entityRef, AgentProjectScope $scope): ?string
    {
        if (! $scope->isSite() || $scope->siteId === null) {
            return null;
        }

        if (preg_match('/^topic:([1-9]\d*)$/', $entityRef, $matches) === 1) {
            $url = KeywordResource::getUrl('cluster', ['topic' => (int) $matches[1]]);

            return $this->domainContext->appendSiteToUrl($url, $scope->siteId);
        }

        if (preg_match('/^coverage:(strong|medium|weak)$/', $entityRef, $matches) !== 1) {
            return null;
        }

        $url = KeywordResource::getUrl('clusters');
        $url .= (str_contains($url, '?') ? '&' : '?').http_build_query(['coverage' => $matches[1]]);

        return $this->domainContext->appendSiteToUrl($url, $scope->siteId);
    }
}
