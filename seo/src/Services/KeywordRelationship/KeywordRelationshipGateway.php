<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\KeywordRelationship;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\KeywordRelationship;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordRelationshipReadModel;

/**
 * Application boundary for one-keyword relationship (Keyword MCP type-2).
 *
 * Approved consumers:
 * 1. Agent MCP capability `keyword.relationship`
 * 2. Keywords Relationship UI
 *
 * On-demand only — never writes seo_mcp_source_snapshots.
 * Not a site landscape (see KeywordLandscapeGateway / keywords.mcp.v2).
 */
final class KeywordRelationshipGateway
{
    public const SCHEMA = KeywordRelationship::SCHEMA;

    public const RELATED_LIMIT = KeywordRelationship::RELATED_LIMIT;

    public const LINK_LIMIT = KeywordRelationship::LINK_LIMIT;

    public const GSC_LIMIT = KeywordRelationship::GSC_LIMIT;

    public function __construct(
        private readonly KeywordRelationshipReadModel $readModel,
    ) {}

    public function forKeyword(int $siteId, int $keywordId): ?KeywordRelationship
    {
        return $this->readModel->relationship($siteId, $keywordId);
    }

    /**
     * Resolve keyword id from input (keyword_id or keyword_ref=keyword:123).
     */
    public function resolveKeywordId(array $input): int
    {
        if (isset($input['keyword_id']) && is_numeric($input['keyword_id'])) {
            return max(0, (int) $input['keyword_id']);
        }

        $ref = trim((string) ($input['keyword_ref'] ?? ''));
        if (preg_match('/^keyword:(\d+)$/i', $ref, $m) === 1) {
            return max(0, (int) $m[1]);
        }

        return 0;
    }

    /**
     * MCP / UI JSON payload. Null relationship → not found (no existence leak across sites).
     *
     * @return array{ok: bool, message?: string, data: array<string, mixed>}
     */
    public function execute(int $siteId, array $input): array
    {
        $keywordId = $this->resolveKeywordId($input);
        if ($siteId <= 0 || $keywordId <= 0) {
            return [
                'ok' => false,
                'message' => 'Keyword not found.',
                'data' => [],
            ];
        }

        $relationship = $this->forKeyword($siteId, $keywordId);
        if (! $relationship instanceof KeywordRelationship) {
            return [
                'ok' => false,
                'message' => 'Keyword not found.',
                'data' => [],
            ];
        }

        return [
            'ok' => true,
            'data' => $relationship->toArray(),
        ];
    }
}
