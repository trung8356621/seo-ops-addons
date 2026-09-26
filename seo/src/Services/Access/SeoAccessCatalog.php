<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Access;

/**
 * Canonical external SEO Access resource catalog (Agent read plane).
 *
 * The temporary Access root is the runtime README for Agents: compact usage
 * guidance plus resource navigation — not a documentation dump.
 */
class SeoAccessCatalog
{
    public const SCHEMA = 'seo.access.v1';

    /**
     * @return array{purpose: string, recommended_flow: list<string>}
     */
    public static function usage(): array
    {
        return [
            'purpose' => 'Read-only SEO context for this site.',
            'recommended_flow' => [
                'Read site first to understand the business and website context.',
                'Read keywords to inspect topical coverage and choose Topics to investigate.',
                'Read gsc when search-performance evidence is needed.',
                'Follow returned href/detail_href links for deeper context.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function resources(): array
    {
        return [
            [
                'key' => 'site',
                'description' => 'Website identity, business context, important pages, and content distribution.',
                'when_to_use' => 'Read first before making content or SEO decisions.',
                'method' => 'GET',
            ],
            [
                'key' => 'keywords',
                'description' => 'Topic landscape with MCP coverage scores.',
                'when_to_use' => 'Use to find weak or strong Topics and inspect Topic DNA and Focus Articles.',
                'method' => 'GET',
                'usage' => [
                    'mcp' => 'Topic coverage score from 0 to 100.',
                    'default_order' => 'Lowest MCP first.',
                    'weakest_topics' => '?sort=mcp&direction=asc',
                    'strongest_topics' => '?sort=mcp&direction=desc',
                    'pagination' => 'Use page/per_page. Maximum per_page is 100.',
                    'detail' => 'Each Topic contains detail_href. Follow it for full DNA and Focus Articles.',
                ],
            ],
            [
                'key' => 'gsc',
                'description' => 'Google Search Console performance and SEO opportunity data.',
                'when_to_use' => 'Use when decisions should be supported by actual search-performance data.',
                'method' => 'GET',
                'usage' => [
                    'period' => 'YYYY-MM. Defaults to current month.',
                    'missing_data' => 'Missing synchronized data must not be interpreted as zero traffic.',
                    'fallback' => 'When available, follow latest_available.href to inspect the most recent synchronized period.',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function resourcesWithHrefs(string $tokenBasePath): array
    {
        $base = rtrim($tokenBasePath, '/');
        $out = [];
        foreach (self::resources() as $resource) {
            $resource['href'] = $base.'/'.$resource['key'];
            $out[] = $resource;
        }

        return $out;
    }
}
