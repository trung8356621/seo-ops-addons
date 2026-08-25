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
        ?SeoMcpSourceSnapshot $gscSnap = null,
    ): array {
        $siteOk = $siteSnap?->isUsable() === true;
        $kwOk = $keywordSnap?->isUsable() === true;
        $gscOk = $gscSnap?->isUsable() === true
            && ! (($gscSnap->metrics_json['absent'] ?? false) === true);
        $siteMetrics = $siteOk ? (array) $siteSnap->metrics_json : [];
        $siteSummary = $siteOk ? (array) $siteSnap->summary_json : [];
        $kwMetrics = $kwOk ? (array) $keywordSnap->metrics_json : [];
        $kwSummary = $kwOk ? (array) $keywordSnap->summary_json : [];
        $kwContext = $kwOk ? (array) $keywordSnap->context_json : [];
        $gscMetrics = $gscOk ? (array) $gscSnap->metrics_json : [];
        $gscSummary = $gscOk ? (array) $gscSnap->summary_json : [];
        $gscContext = $gscOk ? (array) $gscSnap->context_json : [];

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

        if ($gscOk) {
            $falling = (int) ($gscMetrics['falling_count'] ?? 0);
            $ctrOpp = (int) ($gscMetrics['ctr_opportunity_count'] ?? 0);
            $decay = (int) ($gscMetrics['content_decay_count'] ?? 0);
            $newOpp = (int) ($gscMetrics['new_content_opportunity_count'] ?? 0);
            if ($falling > 0 || $decay > 0) {
                $risks[] = [
                    'key' => 'gsc_falling_or_decay',
                    'falling' => $falling,
                    'content_decay' => $decay,
                ];
                $actions[] = [
                    'key' => 'review_gsc_falling_queries',
                    'count' => $falling + $decay,
                ];
            }
            if ($ctrOpp > 0) {
                $opportunities[] = [
                    'key' => 'gsc_ctr_opportunity',
                    'count' => $ctrOpp,
                ];
            }
            if ($newOpp > 0) {
                $opportunities[] = [
                    'key' => 'gsc_new_content_opportunity',
                    'count' => $newOpp,
                ];
            }
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
            'gsc_clicks' => (int) ($gscMetrics['clicks'] ?? 0),
            'gsc_impressions' => (int) ($gscMetrics['impressions'] ?? 0),
            'sources' => [
                'site' => $siteOk,
                'keywords' => $kwOk,
                'gsc' => $gscOk,
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
                'sources_ready' => ($siteOk ? 1 : 0) + ($kwOk ? 1 : 0) + ($gscOk ? 1 : 0),
                'sources_total' => 3,
                'sources' => ['site', 'keywords', 'gsc'],
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
            'gsc_intelligence' => $gscOk ? [
                'metrics' => $gscMetrics,
                'partial' => (bool) ($gscMetrics['partial'] ?? false),
                'planning_signals' => array_slice((array) ($gscContext['planning_signals'] ?? []), 0, 30),
                'ai_lines' => array_slice((array) ($gscContext['ai_lines'] ?? []), 0, 40),
                'note' => (string) ($gscContext['note'] ?? 'GSC impressions ≠ search volume'),
            ] : null,
            'highlights' => $highlights,
            'risks' => $risks,
            'opportunities' => $opportunities,
            'recommended_actions' => $actions,
        ];

        // Ready still requires site+keywords; GSC is optional evidence.
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
