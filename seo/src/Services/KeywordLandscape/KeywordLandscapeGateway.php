<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\KeywordLandscape;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscape;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordLandscapeTopic;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;
use Omnichannel\Addons\Seo\Services\Context\ContextEnvelopeBuilder;

/**
 * Canonical application boundary for site-level Keyword Landscape (context type-1).
 *
 * Approved consumers only:
 * 1. SEO Audit
 * 2. Prompt Generator
 * 3. Keywords / Topical Map
 *
 * Also backs capability `domain.keyword_landscape`.
 * Not a general-purpose Agent/ACL surface — do not wire unrelated modules here.
 *
 * Domain Context ≠ MCP transport. In-process consumers share this gateway (no HTTP loopback).
 * Future HTTP `/api/v1/contexts/sites/{site_ref}/keywords` wraps this class.
 */
final class KeywordLandscapeGateway
{
    public const DNA_LIMIT = KeywordLandscapeReadModel::DNA_LIMIT;

    public const SCHEMA = 'keywords.mcp.v2';

    public const VERSION = 2;

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

    /**
     * Future API / MCP-ready outer envelope (persisted schema id unchanged).
     *
     * @return array<string, mixed>
     */
    public function envelope(int $siteId, bool $includeDna = true): array
    {
        $landscape = $this->forSite($siteId, $includeDna);

        return ContextEnvelopeBuilder::make(
            self::SCHEMA,
            self::VERSION,
            $siteId,
            $landscape->sourceUpdatedAt,
            true,
            $landscape->toArray(),
        );
    }

    /**
     * Keywords / Topical Map overview (approved consumer #3).
     */
    public function topicalMapOverview(int $siteId): \Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapOverview
    {
        return app(\Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel::class)
            ->overview($siteId);
    }

    /**
     * Lazy Topic → Keyword children for Topical Map drill-down.
     */
    public function topicalMapTopicChildren(
        int $siteId,
        int $topicId,
        int $limit = \Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapTopicChildren::MAX_CHILDREN,
    ): ?\Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapTopicChildren {
        return app(\Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapReadModel::class)
            ->topicChildren($siteId, $topicId, $limit);
    }
}
