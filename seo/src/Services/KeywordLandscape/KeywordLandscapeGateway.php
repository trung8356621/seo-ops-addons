<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\KeywordLandscape;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscape;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscapeTopic;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;

/**
 * Canonical application boundary for site-level Keyword Landscape (Keyword MCP type-1).
 *
 * Approved consumers only:
 * 1. SEO Audit
 * 2. Prompt Generator (future)
 * 3. Keywords / Topical Map (future)
 *
 * Also backs capability `domain.keyword_landscape` and monthly snapshot source `keywords`.
 * Not a general-purpose Agent/ACL surface — do not wire unrelated modules here.
 *
 * HTTP MCP and in-process consumers share this gateway (no HTTP loopback).
 */
final class KeywordLandscapeGateway
{
    public const DNA_LIMIT = KeywordLandscapeReadModel::DNA_LIMIT;

    public function __construct(
        private readonly KeywordLandscapeReadModel $readModel,
    ) {}

    public function forSite(int $siteId, bool $includeDna = true): KeywordLandscape
    {
        return $this->readModel->forSite($siteId, $includeDna);
    }

    public function findTopic(int $siteId, int $topicId, bool $includeDna = true): ?KeywordLandscapeTopic
    {
        return $this->readModel->findTopic($siteId, $topicId, $includeDna);
    }

    public function sourceUpdatedAt(int $siteId): ?string
    {
        return $this->readModel->sourceUpdatedAt($siteId);
    }
}
