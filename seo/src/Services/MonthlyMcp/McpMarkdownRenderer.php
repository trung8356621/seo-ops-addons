<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Models\SeoMcpReport;
use Omnichannel\Addons\Seo\Models\SeoMcpSourceSnapshot;

/**
 * Single source of truth for MCP markdown (UI preview + AI context).
 */
final class McpMarkdownRenderer
{
    private const MAX_FINDINGS = 8;

    private const MAX_CLUSTERS = 10;

    private const MAX_GROUPS = 8;

    private const MAX_LIST_ITEMS = 8;

    public function __construct(
        private readonly McpPeriodService $periods,
        private readonly MonthlyMcpSnapshotService $snapshots,
        private readonly MonthlyMcpReportService $reports,
    ) {}

    public function renderSite(int $siteId, string $periodKey): string
    {
        $bundle = $this->resolve($siteId, $periodKey);
        if ($bundle === null) {
            return $this->missingSource('Website Intelligence', $siteId, $periodKey);
        }

        return $this->renderSiteFromSnapshot(
            $bundle['domain'],
            $periodKey,
            $bundle['site_snap'],
            $bundle['period'],
        );
    }

    public function renderKeywords(int $siteId, string $periodKey): string
    {
        $bundle = $this->resolve($siteId, $periodKey);
        if ($bundle === null) {
            return $this->missingSource('Keyword Intelligence', $siteId, $periodKey);
        }

        return $this->renderKeywordsFromSnapshot(
            $bundle['domain'],
            $periodKey,
            $bundle['keyword_snap'],
            $bundle['period'],
        );
    }

    public function renderCombined(int $siteId, string $periodKey): string
    {
        $bundle = $this->resolve($siteId, $periodKey);
        if ($bundle === null) {
            return $this->missingSource('SEO MCP Intelligence', $siteId, $periodKey);
        }

        $siteMd = $this->renderSiteFromSnapshot(
            $bundle['domain'],
            $periodKey,
            $bundle['site_snap'],
            $bundle['period'],
        );
        $kwMd = $this->renderKeywordsFromSnapshot(
            $bundle['domain'],
            $periodKey,
            $bundle['keyword_snap'],
            $bundle['period'],
        );

        return $this->renderCombinedBody(
            $bundle['domain'],
            $periodKey,
            $bundle['period'],
            $bundle['report'],
            $bundle['site_snap'],
            $bundle['keyword_snap'],
            $siteMd,
            $kwMd,
        );
    }

    /**
     * @return array{
     *   period: SeoMcpPeriod,
     *   domain: string,
     *   site_snap: ?SeoMcpSourceSnapshot,
     *   keyword_snap: ?SeoMcpSourceSnapshot,
     *   report: ?SeoMcpReport
     * }|null
     */
    private function resolve(int $siteId, string $periodKey): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m) !== 1) {
            return null;
        }
        $period = $this->periods->find((int) $m[1], (int) $m[2]);
        if (! $period instanceof SeoMcpPeriod) {
            return null;
        }
        $siteSnap = $this->snapshots->find($period, $siteId, McpSourceKey::Site);
        $keywordSnap = $this->snapshots->find($period, $siteId, McpSourceKey::Keywords);
        $domain = $this->domainFromSnapshots($siteSnap, $keywordSnap);
        if ($domain === '') {
            $site = Site::query()->find($siteId);
            $domain = $site instanceof Site ? (string) ($site->domain ?? '') : '';
        }
        if ($domain === '') {
            return null;
        }

        return [
            'period' => $period,
            'domain' => $domain,
            'site_snap' => $siteSnap,
            'keyword_snap' => $keywordSnap,
            'report' => $this->reports->find($period, $siteId),
        ];
    }

    private function domainFromSnapshots(?SeoMcpSourceSnapshot $siteSnap, ?SeoMcpSourceSnapshot $keywordSnap): string
    {
        foreach ([$siteSnap, $keywordSnap] as $snap) {
            if (! $snap instanceof SeoMcpSourceSnapshot) {
                continue;
            }
            $summary = is_array($snap->summary_json) ? $snap->summary_json : [];
            $identity = is_array($summary['identity'] ?? null) ? $summary['identity'] : [];
            $domain = trim((string) ($identity['domain'] ?? ''));
            if ($domain !== '') {
                return $domain;
            }
        }

        return '';
    }

    private function renderSiteFromSnapshot(
        string $domain,
        string $periodKey,
        ?SeoMcpSourceSnapshot $snap,
        SeoMcpPeriod $period,
    ): string {
        if (! $snap instanceof SeoMcpSourceSnapshot || ! $snap->isUsable()) {
            return "# Website Intelligence\n\nNo site MCP snapshot for {$domain} · {$this->periodLabel($periodKey)}.";
        }

        $metrics = is_array($snap->metrics_json) ? $snap->metrics_json : [];
        $summary = is_array($snap->summary_json) ? $snap->summary_json : [];
        $distribution = is_array($summary['content_distribution'] ?? null) ? $summary['content_distribution'] : [];
        $internalLinking = is_array($summary['internal_linking'] ?? null) ? $summary['internal_linking'] : [];
        $publishing = is_array($summary['publishing_status'] ?? null) ? $summary['publishing_status'] : [];
        $articles = is_array($summary['articles'] ?? null) ? $summary['articles'] : [];
        $indexability = is_array($summary['indexability'] ?? null) ? $summary['indexability'] : [];
        $linkHealth = is_array($summary['link_health'] ?? null) ? $summary['link_health'] : [];
        if ($internalLinking !== []) {
            $linkHealth = array_merge($linkHealth, $internalLinking);
        }
        $findings = is_array($summary['findings']['top'] ?? null) ? $summary['findings']['top'] : [];
        $qualityWarnings = is_array($summary['data_quality']['warnings'] ?? null) ? $summary['data_quality']['warnings'] : [];
        $health = $this->humanHealth((string) ($metrics['health'] ?? 'unknown'));
        $indexable = (int) ($indexability['indexable'] ?? $metrics['indexable'] ?? 0);
        $noindex = (int) ($indexability['noindex'] ?? $metrics['noindex'] ?? 0);
        $indexTotal = $indexable + $noindex;
        $distributionAvailable = (bool) ($distribution['available'] ?? ($distribution !== [] && array_key_exists('posts', $distribution)));

        $lines = [
            '# Website Intelligence',
            '',
            '## Site Summary',
            '',
            '- Articles: '.$this->formatMetric($this->metricInt($metrics, 'article_total') ?? $this->metricInt($articles, 'total')),
            '- Published articles: '.$this->formatMetric($this->metricInt($metrics, 'article_published') ?? $this->metricInt($articles, 'published')),
            '- Categories: '.$this->formatDistributionMetric($distribution, 'categories', $distributionAvailable),
            '- Internal links: '.$this->formatLinkMetric($linkHealth, $metrics, 'internal_links', 'total_internal_links'),
            '- Internally linked articles: '.$this->formatLinkMetric($linkHealth, $metrics, 'internally_linked_articles', 'linked_articles'),
            '- Website health: '.$health,
            '- Indexable articles: '.$indexable.' / '.max($indexTotal, (int) ($metrics['article_total'] ?? $articles['total'] ?? 0)),
            '',
            '## Content Distribution',
            '',
            '- Posts: '.$this->formatDistributionMetric($distribution, 'posts', $distributionAvailable),
            '- Categories: '.$this->formatDistributionMetric($distribution, 'categories', $distributionAvailable),
            '- Products: '.$this->formatDistributionMetric($distribution, 'products', $distributionAvailable),
            '- Product categories: '.$this->formatDistributionMetric($distribution, 'product_categories', $distributionAvailable),
            '- Other: '.$this->formatDistributionMetric($distribution, 'other', $distributionAvailable),
            '',
            '## Publishing Status',
            '',
            '- Published: '.$this->num((int) ($publishing['published'] ?? $articles['published'] ?? 0)),
            '- Draft: '.$this->num((int) ($publishing['draft'] ?? $articles['draft'] ?? 0)),
            '- Scheduled: '.$this->num((int) ($publishing['scheduled'] ?? $articles['scheduled'] ?? 0)),
            '- Private: '.$this->num((int) ($publishing['private'] ?? $articles['private'] ?? 0)),
            '- Other: '.$this->num((int) ($publishing['other'] ?? $articles['other'] ?? 0)),
            '',
            '## Internal Linking',
            '',
            '- Total internal links: '.$this->formatLinkMetric($linkHealth, $metrics, 'internal_links', 'total_internal_links'),
            '- Articles receiving internal links: '.$this->formatLinkMetric($linkHealth, $metrics, 'internally_linked_articles', 'linked_articles'),
            '- Average links per linked article: '.$this->formatAverageLinkMetric($linkHealth),
            '- Articles without internal links: '.$this->formatLinkMetric($linkHealth, $metrics, 'articles_without_internal_links', 'articles_without_internal_links'),
        ];

        $topLinked = is_array($linkHealth['top_linked_articles'] ?? null) ? $linkHealth['top_linked_articles'] : [];
        if ($topLinked !== []) {
            $lines[] = '';
            $lines[] = '## Internal Linking Highlights';
            $lines[] = '';
            $lines[] = '- Total linked articles: '.$this->formatLinkMetric($linkHealth, $metrics, 'internally_linked_articles', 'linked_articles');
            $lines[] = '';
            $lines[] = 'Top linked articles:';
            $rank = 1;
            foreach (array_slice($topLinked, 0, 3) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? 'Untitled'));
                $count = (int) ($row['internal_links'] ?? 0);
                $lines[] = "{$rank}. {$title} — {$count} internal links";
                $rank++;
            }
            $single = (int) ($linkHealth['articles_single_internal_link'] ?? $metrics['articles_single_internal_link'] ?? 0);
            $without = (int) ($linkHealth['articles_without_internal_links'] ?? $metrics['articles_without_internal_links'] ?? 0);
            if ($without > 0 || $single > 0) {
                $lines[] = '';
                $lines[] = 'Potential issues:';
                if ($without > 0) {
                    $lines[] = "- {$without} articles have no internal links";
                }
                if ($single > 0) {
                    $lines[] = "- {$single} articles have only 1 internal link";
                }
            }
        }

        if ($findings !== []) {
            $lines[] = '';
            $lines[] = '## Important Findings';
            $lines[] = '';
            foreach (array_slice($findings, 0, self::MAX_FINDINGS) as $finding) {
                if (! is_array($finding)) {
                    continue;
                }
                $title = trim((string) ($finding['title'] ?? $finding['type'] ?? 'Finding'));
                $severity = trim((string) ($finding['severity'] ?? ''));
                $lines[] = '- '.($severity !== '' ? "[{$severity}] " : '').$title;
            }
        }

        if ($qualityWarnings !== []) {
            $lines[] = '';
            $lines[] = '## Data Quality Notes';
            $lines[] = '';
            foreach ($qualityWarnings as $warning) {
                if (is_string($warning) && $warning !== '') {
                    $lines[] = '- '.$warning;
                }
            }
        }

        if (! $distributionAvailable && $distribution === []) {
            $lines[] = '';
            $lines[] = '_Content distribution not available in this snapshot. Refresh the MCP report to rebuild._';
        }

        if (($linkHealth['available'] ?? null) === false && $internalLinking === []) {
            $lines[] = '';
            $lines[] = '_Internal linking metrics not available in this snapshot. Refresh the MCP report to rebuild._';
        }

        $lines[] = '';
        $lines[] = '_Snapshot: '.$this->snapshotMeta($snap, $period).'_';

        return implode("\n", $lines);
    }

    private function renderKeywordsFromSnapshot(
        string $domain,
        string $periodKey,
        ?SeoMcpSourceSnapshot $snap,
        SeoMcpPeriod $period,
    ): string {
        if (! $snap instanceof SeoMcpSourceSnapshot || ! $snap->isUsable()) {
            return "# Keyword Intelligence\n\nNo keyword MCP snapshot for {$domain} · {$this->periodLabel($periodKey)}.";
        }

        $metrics = is_array($snap->metrics_json) ? $snap->metrics_json : [];
        $summary = is_array($snap->summary_json) ? $snap->summary_json : [];
        $context = is_array($snap->context_json) ? $snap->context_json : [];
        $generation = is_array($context['generation_context'] ?? null) ? $context['generation_context'] : [];
        $groups = is_array($summary['groups'] ?? null) ? $summary['groups'] : [];
        $clusters = is_array($summary['clusters'] ?? null) ? $summary['clusters'] : [];
        $weakClusters = is_array($summary['weak_clusters'] ?? null) ? $summary['weak_clusters'] : [];
        $intentGaps = is_array($generation['intent_gaps'] ?? null) ? $generation['intent_gaps'] : [];
        $missingDirections = is_array($generation['missing_directions'] ?? null) ? $generation['missing_directions'] : [];
        $seoKeywords = max(0, (int) ($metrics['total'] ?? 0) - (int) ($metrics['excluded'] ?? 0));

        $lines = [
            '# Keyword Intelligence',
            '',
            '## Keyword Summary',
            '',
            '- Focus keywords: '.$this->num((int) ($metrics['focus'] ?? 0)),
            '- SEO keywords: '.$this->num($seoKeywords),
            '- Non-SEO / Noise: '.$this->num((int) ($metrics['excluded'] ?? 0)),
            '- Errors: '.$this->num((int) ($metrics['error'] ?? 0)),
            '- Topic clusters: '.$this->num((int) ($metrics['clusters'] ?? 0)),
            '- Unclustered keywords: '.$this->num((int) ($metrics['unclustered'] ?? 0)),
            '- Keywords with internal links: '.$this->num((int) ($metrics['linked'] ?? 0)),
            '',
            '## Classification',
            '',
        ];

        if ($groups === []) {
            $lines[] = '- No keyword groups captured in this snapshot.';
        } else {
            foreach (array_slice($groups, 0, self::MAX_GROUPS) as $group) {
                if (! is_array($group)) {
                    continue;
                }
                $label = trim((string) ($group['label'] ?? $group['key'] ?? 'Group'));
                $lines[] = '- '.$label.': '.$this->num((int) ($group['count'] ?? 0));
            }
        }

        if ($clusters !== []) {
            $lines[] = '';
            $lines[] = '## Topic Clusters';
            $lines[] = '';
            foreach (array_slice($clusters, 0, self::MAX_CLUSTERS) as $cluster) {
                if (! is_array($cluster)) {
                    continue;
                }
                $name = trim((string) ($cluster['name'] ?? $cluster['cluster_id'] ?? 'Cluster'));
                $lines[] = '### '.$name;
                $lines[] = '- Keywords: '.$this->num((int) ($cluster['keyword_count'] ?? 0));
                $lines[] = '- Linked articles: '.$this->num((int) ($cluster['linked_articles_count'] ?? $cluster['article_count'] ?? 0));
                $lines[] = '- Status: '.trim((string) ($cluster['coverage'] ?? 'unknown'));
                $lines[] = '';
            }
        }

        $issues = [];
        if ((int) ($metrics['error'] ?? 0) > 0) {
            $issues[] = '- '.$this->num((int) $metrics['error']).' keywords flagged as Error';
        }
        if ((int) ($metrics['unclustered'] ?? 0) > 0) {
            $issues[] = '- '.$this->num((int) $metrics['unclustered']).' keywords are not assigned to a topic cluster';
        }
        foreach (array_slice($weakClusters, 0, 5) as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $name = trim((string) ($cluster['name'] ?? $cluster['cluster_id'] ?? 'Cluster'));
            $issues[] = '- Weak cluster coverage: '.$name.' ('.$this->num((int) ($cluster['keyword_count'] ?? 0)).' keywords · '.$this->num((int) ($cluster['article_count'] ?? 0)).' linked articles)';
        }
        if ($issues !== []) {
            $lines[] = '## Keyword Issues';
            $lines[] = '';
            array_push($lines, ...$issues);
        }

        $opportunities = [];
        foreach (array_slice($weakClusters, 0, self::MAX_LIST_ITEMS) as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $name = trim((string) ($cluster['name'] ?? $cluster['cluster_id'] ?? 'Cluster'));
            $opportunities[] = '- Expand cluster "'.$name.'" ('.$this->num((int) ($cluster['keyword_count'] ?? 0)).' keywords · '.$this->num((int) ($cluster['article_count'] ?? 0)).' linked articles)';
        }
        foreach (array_slice((array) ($generation['weak_topics'] ?? []), 0, 5) as $topic) {
            if (! is_string($topic) || trim($topic) === '') {
                continue;
            }
            $opportunities[] = '- Weak topic: '.trim($topic);
        }
        if ($opportunities !== []) {
            $lines[] = '';
            $lines[] = '## Opportunities';
            $lines[] = '';
            array_push($lines, ...array_slice($opportunities, 0, self::MAX_LIST_ITEMS));
        }

        if ($missingDirections !== [] || $intentGaps !== []) {
            $lines[] = '';
            $lines[] = '## Missing Directions';
            $lines[] = '';
            foreach (array_slice($missingDirections, 0, self::MAX_LIST_ITEMS) as $row) {
                if (is_string($row) && trim($row) !== '') {
                    $lines[] = '- '.trim($row);

                    continue;
                }
                if (! is_array($row)) {
                    continue;
                }
                $cluster = trim((string) ($row['cluster'] ?? $row['name'] ?? 'Cluster'));
                $direction = trim((string) ($row['direction'] ?? $row['missing_direction'] ?? ''));
                $lines[] = '- '.$cluster.($direction !== '' ? ': '.$direction : '');
            }
            foreach (array_slice($intentGaps, 0, self::MAX_LIST_ITEMS) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $cluster = trim((string) ($row['cluster'] ?? 'Cluster'));
                $intent = trim((string) ($row['missing_intent'] ?? $row['intent'] ?? ''));
                if ($intent === '') {
                    continue;
                }
                $lines[] = '- Intent gap in '.$cluster.': '.$intent;
            }
        }

        $lines[] = '';
        $lines[] = '_Snapshot: '.$this->snapshotMeta($snap, $period).'_';

        return implode("\n", $lines);
    }

    private function renderCombinedBody(
        string $domain,
        string $periodKey,
        SeoMcpPeriod $period,
        ?SeoMcpReport $report,
        ?SeoMcpSourceSnapshot $siteSnap,
        ?SeoMcpSourceSnapshot $keywordSnap,
        string $siteMd,
        string $kwMd,
    ): string {
        $siteStatus = $this->snapshotStatusLabel($siteSnap);
        $keywordStatus = $this->snapshotStatusLabel($keywordSnap);
        $periodStatus = $period->isFinalized() ? 'Finalized' : 'Open';
        $reportStatus = $report instanceof SeoMcpReport ? (string) ($report->status ?? 'missing') : 'missing';

        $lines = [
            '# SEO MCP Intelligence',
            '',
            '## Context',
            '',
            '- Domain: '.$domain,
            '- Period: '.$this->periodLabel($periodKey),
            '- MCP status: '.$periodStatus,
            '- Report status: '.$reportStatus,
            '- Website snapshot: '.$siteStatus,
            '- Keyword snapshot: '.$keywordStatus,
            '',
            '---',
            '',
            '# 1. Website Intelligence',
            '',
            $this->stripTopHeading($siteMd, '# Website Intelligence'),
            '',
            '---',
            '',
            '# 2. Keyword Intelligence',
            '',
            $this->stripTopHeading($kwMd, '# Keyword Intelligence'),
        ];

        if ($report instanceof SeoMcpReport) {
            $highlights = is_array($report->highlights_json) ? $report->highlights_json : [];
            $risks = is_array($report->risks_json) ? $report->risks_json : [];
            $opportunities = is_array($report->opportunities_json) ? $report->opportunities_json : [];

            $lines[] = '';
            $lines[] = '---';
            $lines[] = '';
            $lines[] = '# 3. Key Findings';
            $lines[] = '';
            $lines = array_merge($lines, $this->reportItemLines($highlights, 'No key findings recorded.'));
            $lines[] = '';
            $lines[] = '# 4. SEO Opportunities';
            $lines[] = '';
            $lines = array_merge($lines, $this->reportItemLines($opportunities, 'No SEO opportunities recorded.'));
            $lines[] = '';
            $lines[] = '# 5. Issues Requiring Attention';
            $lines[] = '';
            $lines = array_merge($lines, $this->reportItemLines($risks, 'No issues requiring attention.'));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private function reportItemLines(array $items, string $empty): array
    {
        if ($items === []) {
            return ['- '.$empty];
        }
        $lines = [];
        foreach (array_slice($items, 0, self::MAX_LIST_ITEMS) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $this->reportItemText($item);
            if ($text !== '') {
                $lines[] = '- '.$text;
            }
        }

        return $lines !== [] ? $lines : ['- '.$empty];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function reportItemText(array $item): string
    {
        $key = (string) ($item['key'] ?? '');
        $name = (string) ($item['name'] ?? $item['group_key'] ?? '');
        $count = (int) ($item['count'] ?? 0);

        return match ($key) {
            'strong_group' => "Strong keyword group {$name} ({$count} keywords)",
            'strong_cluster' => "Strong topic cluster {$name}",
            'keyword_error' => "{$count} keywords flagged as Error",
            'unclustered_keywords' => "{$count} keywords are not assigned to a topic cluster",
            'seo_findings' => "{$count} SEO findings require review",
            'weak_group' => "Weak keyword group {$name} ({$count} keywords)",
            'weak_cluster' => 'Weak cluster "'.$name.'" ('.(int) ($item['keyword_count'] ?? 0).' keywords · '.(int) ($item['article_count'] ?? 0).' linked articles)',
            default => trim($name !== '' ? $name : $key).($count > 0 ? ' · '.$count : ''),
        };
    }

    private function missingSource(string $title, int $siteId, string $periodKey): string
    {
        return "# {$title}\n\nNo MCP data for site #{$siteId} · {$this->periodLabel($periodKey)}.";
    }

    private function stripTopHeading(string $markdown, string $heading): string
    {
        $trimmed = ltrim($markdown);
        if (str_starts_with($trimmed, $heading)) {
            $trimmed = ltrim(substr($trimmed, strlen($heading)));
        }

        return ltrim($trimmed, "\n");
    }

    private function periodLabel(string $periodKey): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m) !== 1) {
            return $periodKey;
        }

        return sprintf('%02d/%s', (int) $m[2], $m[1]);
    }

    private function num(int $value): string
    {
        return number_format($value);
    }

    private function formatMetric(?int $value): string
    {
        return $value === null ? 'N/A' : $this->num($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function metricInt(array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return (int) $data[$key];
    }

    /**
     * @param  array<string, mixed>  $distribution
     */
    private function formatDistributionMetric(array $distribution, string $key, bool $available): string
    {
        if (! $available || ! array_key_exists($key, $distribution)) {
            return 'N/A';
        }
        if ($distribution[$key] === null) {
            return 'N/A';
        }

        return $this->num((int) $distribution[$key]);
    }

    /**
     * @param  array<string, mixed>  $linkHealth
     * @param  array<string, mixed>  $metrics
     */
    private function formatLinkMetric(array $linkHealth, array $metrics, string $legacyKey, string $modernKey): string
    {
        if (array_key_exists($modernKey, $linkHealth) && $linkHealth[$modernKey] !== null) {
            return $this->num((int) $linkHealth[$modernKey]);
        }
        if (array_key_exists($legacyKey, $linkHealth) && $linkHealth[$legacyKey] !== null) {
            return $this->num((int) $linkHealth[$legacyKey]);
        }
        if (array_key_exists($modernKey, $metrics) && $metrics[$modernKey] !== null) {
            return $this->num((int) $metrics[$modernKey]);
        }
        if (array_key_exists($legacyKey, $metrics) && $metrics[$legacyKey] !== null) {
            return $this->num((int) $metrics[$legacyKey]);
        }

        return 'N/A';
    }

    /**
     * @param  array<string, mixed>  $linkHealth
     */
    private function formatAverageLinkMetric(array $linkHealth): string
    {
        $value = $linkHealth['average_links_per_linked_article'] ?? null;
        if ($value === null) {
            return 'N/A';
        }

        return (string) $value;
    }

    private function humanHealth(string $health): string
    {
        return match ($health) {
            'healthy', 'ok' => 'Healthy',
            'degraded' => 'Degraded',
            'unhealthy', 'error', 'failed' => 'Unhealthy',
            default => $health !== '' && $health !== 'unknown' ? ucfirst($health) : 'Unknown',
        };
    }

    private function snapshotStatusLabel(?SeoMcpSourceSnapshot $snap): string
    {
        if (! $snap instanceof SeoMcpSourceSnapshot) {
            return 'Missing';
        }
        if (! $snap->isUsable()) {
            return 'Failed';
        }

        return 'Ready · '.$snap->generated_at?->toDateTimeString();
    }

    private function snapshotMeta(SeoMcpSourceSnapshot $snap, SeoMcpPeriod $period): string
    {
        $status = $period->isFinalized() ? 'finalized' : 'open';

        return (string) ($snap->source ?? 'source').' · '.$status.' · '.$snap->generated_at?->toDateTimeString();
    }
}
