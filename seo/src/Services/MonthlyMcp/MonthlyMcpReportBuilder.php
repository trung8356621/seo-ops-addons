<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Omnichannel\Addons\Seo\Enums\McpReportStatus;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;

final class MonthlyMcpReportBuilder
{
    public const SCHEMA = 'mcp.monthly.v1';

    public const MAX_ITEMS = 10;

    /**
     * @return array{
     *   overview: array<string, mixed>,
     *   highlights: list<array<string, mixed>>,
     *   risks: list<array<string, mixed>>,
     *   opportunities: list<array<string, mixed>>,
     *   recommended_actions: list<array<string, mixed>>,
     *   ai_context: array<string, mixed>,
     *   status: string
     * }
     */
    public function build(
        SeoMcpPeriod $period,
        int $siteId,
        string $domain,
        ?SeoMcpSourceSnapshot $siteSnap,
        ?SeoMcpSourceSnapshot $keywordSnap,
    ): array {
        $siteOk = $siteSnap?->isUsable() === true;
        $kwOk = $keywordSnap?->isUsable() === true;
        $siteMetrics = $siteOk ? (array) $siteSnap->metrics_json : [];
        $siteSummary = $siteOk ? (array) $siteSnap->summary_json : [];
        $kwMetrics = $kwOk ? (array) $keywordSnap->metrics_json : [];
        $kwSummary = $kwOk ? (array) $keywordSnap->summary_json : [];
        $kwContext = $kwOk ? (array) $keywordSnap->context_json : [];

        $highlights = [];
        $risks = [];
        $opportunities = [];
        $actions = [];

        foreach ((array) ($kwSummary['groups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }
            $count = (int) ($group['count'] ?? 0);
            if ($count >= 10) {
                $highlights[] = [
                    'key' => 'strong_group',
                    'group_key' => (string) ($group['key'] ?? ''),
                    'name' => (string) ($group['label'] ?? $group['key'] ?? ''),
                    'count' => $count,
                ];
            } elseif ($count > 0 && $count <= 6) {
                $opportunities[] = [
                    'key' => 'weak_group',
                    'group_key' => (string) ($group['key'] ?? ''),
                    'name' => (string) ($group['label'] ?? $group['key'] ?? ''),
                    'count' => $count,
                ];
                $actions[] = [
                    'key' => 'expand_group',
                    'group_key' => (string) ($group['key'] ?? ''),
                    'name' => (string) ($group['label'] ?? ''),
                    'count' => $count,
                ];
            }
        }
        foreach (array_slice((array) ($kwSummary['strong_clusters'] ?? []), 0, 5) as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $highlights[] = [
                'key' => 'strong_cluster',
                'cluster_id' => (string) ($cluster['cluster_id'] ?? ''),
                'name' => (string) ($cluster['name'] ?? ''),
                'coverage' => (string) ($cluster['coverage'] ?? ''),
            ];
        }

        $errorCount = (int) ($kwMetrics['error'] ?? 0);
        if ($errorCount > 0) {
            $risks[] = [
                'key' => 'keyword_error',
                'count' => $errorCount,
            ];
            $actions[] = [
                'key' => 'review_keyword_errors',
                'count' => $errorCount,
            ];
        }
        $unclustered = (int) ($kwMetrics['unclustered'] ?? 0);
        if ($unclustered > 0) {
            $risks[] = [
                'key' => 'unclustered_keywords',
                'count' => $unclustered,
            ];
            $actions[] = [
                'key' => 'review_unclustered',
                'count' => $unclustered,
            ];
        }
        $critical = (int) ($siteMetrics['critical_findings'] ?? 0) + (int) ($siteMetrics['high_findings'] ?? 0);
        if ($critical > 0) {
            $risks[] = [
                'key' => 'seo_findings',
                'count' => $critical,
            ];
            $actions[] = [
                'key' => 'review_seo_findings',
                'count' => $critical,
            ];
        }
        foreach (array_slice((array) ($kwSummary['weak_clusters'] ?? []), 0, 6) as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $opportunities[] = [
                'key' => 'weak_cluster',
                'cluster_id' => (string) ($cluster['cluster_id'] ?? ''),
                'name' => (string) ($cluster['name'] ?? ''),
                'signal' => 'weak_coverage',
                'keyword_count' => (int) ($cluster['keyword_count'] ?? 0),
                'article_count' => (int) ($cluster['article_count'] ?? 0),
            ];
            $actions[] = [
                'key' => 'expand_cluster',
                'cluster_id' => (string) ($cluster['cluster_id'] ?? ''),
                'name' => (string) ($cluster['name'] ?? ''),
            ];
        }

        $highlights = array_slice($highlights, 0, self::MAX_ITEMS);
        $risks = array_slice($risks, 0, self::MAX_ITEMS);
        $opportunities = array_slice($opportunities, 0, self::MAX_ITEMS);
        $actions = array_slice($actions, 0, self::MAX_ITEMS);

        $overview = [
            'site_health' => (string) ($siteMetrics['health'] ?? 'unknown'),
            'keyword_total' => (int) ($kwMetrics['total'] ?? 0),
            'focus' => (int) ($kwMetrics['focus'] ?? 0),
            'error' => $errorCount,
            'excluded' => (int) ($kwMetrics['excluded'] ?? 0),
            'clusters' => (int) ($kwMetrics['clusters'] ?? 0),
            'unclustered' => (int) ($kwMetrics['unclustered'] ?? 0),
            'article_total' => (int) ($siteMetrics['article_total'] ?? 0),
            'article_published' => (int) ($siteMetrics['article_published'] ?? 0),
            'sources' => [
                'site' => $siteOk,
                'keywords' => $kwOk,
            ],
        ];

        $aiContext = [
            'schema' => self::SCHEMA,
            'period' => $period->periodKey(),
            'site' => [
                'site_id' => $siteId,
                'domain' => $domain,
                'health' => $overview['site_health'],
                'indexability_warnings' => (int) ($siteMetrics['noindex'] ?? 0),
            ],
            'coverage' => [
                'sources_ready' => ($siteOk ? 1 : 0) + ($kwOk ? 1 : 0),
                'sources_total' => 2,
                'sources' => ['site', 'keywords'],
            ],
            'site_intelligence' => $siteOk ? [
                'metrics' => $siteMetrics,
                'findings' => array_slice((array) (($siteSummary['findings']['top'] ?? [])), 0, 8),
                'link_health' => $siteSummary['link_health'] ?? [],
            ] : null,
            'keyword_intelligence' => $kwOk ? [
                'metrics' => $kwMetrics,
                'strong_groups' => array_slice((array) ($kwSummary['groups'] ?? []), 0, 8),
                'weak_groups' => array_values(array_filter(
                    (array) ($kwSummary['groups'] ?? []),
                    static fn (mixed $g): bool => is_array($g) && (int) ($g['count'] ?? 0) > 0 && (int) ($g['count'] ?? 0) <= 6,
                )),
                'weak_clusters' => array_slice((array) ($kwSummary['weak_clusters'] ?? []), 0, 10),
                'intent_gaps' => array_slice((array) (($kwContext['generation_context']['intent_gaps'] ?? [])), 0, 10),
                'missing_directions' => array_slice((array) (($kwContext['generation_context']['missing_directions'] ?? [])), 0, 10),
            ] : null,
            'highlights' => $highlights,
            'risks' => $risks,
            'opportunities' => $opportunities,
            'recommended_actions' => $actions,
        ];

        $status = ($siteOk && $kwOk)
            ? McpReportStatus::Ready->value
            : (($siteOk || $kwOk) ? McpReportStatus::Incomplete->value : McpReportStatus::Missing->value);

        return [
            'overview' => $overview,
            'highlights' => $highlights,
            'risks' => $risks,
            'opportunities' => $opportunities,
            'recommended_actions' => $actions,
            'ai_context' => $aiContext,
            'status' => $status,
        ];
    }
}
