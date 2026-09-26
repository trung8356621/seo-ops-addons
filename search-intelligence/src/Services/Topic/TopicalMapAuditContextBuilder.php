<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use App\Models\Site;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextGateway;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextGateway;
use Throwable;

/**
 * Builds Topical Map Audit prompt context from live canonical gateways.
 * Replaces Monthly MCP markdown/snapshot bundles — no seo_mcp_* tables.
 */
final class TopicalMapAuditContextBuilder
{
    private const MAX_TOPICS = 40;

    private const MAX_GSC_LINES = 24;

    public function __construct(
        private readonly SiteContextGateway $siteContext,
        private readonly KeywordLandscapeGateway $landscape,
        private readonly GscContextGateway $gsc,
        private readonly SiteDomainPromptContextService $domainPromptContext,
    ) {}

    public function buildMarkdown(Site $site, string $periodKey): string
    {
        $siteId = (int) $site->id;
        $domain = trim((string) ($site->domain ?? ''));
        $domainPayload = [];
        try {
            $domainPayload = $this->domainPromptContext->getForSite($siteId);
        } catch (Throwable) {
            $domainPayload = [];
        }

        $companyShort = trim((string) ($domainPayload['company_short_identity'] ?? ''));
        $shortDescription = trim((string) ($domainPayload['short_description'] ?? ''));

        $sections = [
            '# SEO Audit Context',
            '',
            '- Domain: '.($domain !== '' ? $domain : '(unknown)'),
            '- Period: '.$periodKey,
            '- Generated at: '.now()->toDateTimeString(),
            '- Source: live Site Knowledge + Site Context + Keyword Landscape + GSC (when available)',
        ];

        if ($companyShort !== '') {
            $sections[] = '- Company short identity: '.$companyShort;
        }
        if ($shortDescription !== '') {
            $sections[] = '- Short description: '.$shortDescription;
        }

        $sections[] = '';
        $sections[] = '---';
        $sections[] = '';
        $sections[] = $this->renderSiteSection($site, $periodKey);
        $sections[] = '';
        $sections[] = $this->renderKeywordSection($siteId);
        $sections[] = '';
        $sections[] = $this->renderGscSection($siteId, $periodKey);

        return implode("\n", $sections);
    }

    private function renderSiteSection(Site $site, string $periodKey): string
    {
        $lines = ['## Site Context', ''];
        try {
            $ctx = $this->siteContext->forSite($site, $periodKey);
            $summary = $ctx->summary;
            $metrics = $ctx->metrics;
            $lines[] = '- Available: '.($ctx->available() ? 'yes' : 'no');
            $lines[] = '- Source updated at: '.($ctx->sourceUpdatedAt() ?? 'unknown');
            $health = is_array($summary['health'] ?? null) ? $summary['health'] : [];
            if ($health !== []) {
                $lines[] = '- Health: '.json_encode($health, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $indexability = is_array($summary['indexability'] ?? null) ? $summary['indexability'] : [];
            if ($indexability !== []) {
                $lines[] = '- Indexability: '.json_encode($indexability, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $findings = is_array($summary['findings'] ?? null) ? $summary['findings'] : [];
            $top = is_array($findings['top'] ?? null) ? $findings['top'] : [];
            if ($top !== []) {
                $lines[] = '- Top findings:';
                foreach (array_slice($top, 0, 8) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $lines[] = '  - '.trim((string) ($row['severity'] ?? '')).' '.(string) ($row['type'] ?? 'finding');
                }
            }
            if ($metrics !== []) {
                $lines[] = '- Metrics keys: '.implode(', ', array_keys($metrics));
            }
        } catch (Throwable $e) {
            $lines[] = '- Unavailable: '.mb_substr($e->getMessage(), 0, 160);
        }

        return implode("\n", $lines);
    }

    private function renderKeywordSection(int $siteId): string
    {
        $lines = ['## Keyword Landscape', ''];
        try {
            $landscape = $this->landscape->forSite($siteId, true);
            $lines[] = '- Topic count: '.$landscape->topicCount();
            $lines[] = '- Source updated at: '.($landscape->sourceUpdatedAt ?? 'unknown');
            $lines[] = '';
            $lines[] = 'Topics (name · coverage · mcp · articles · dna · status):';
            $i = 0;
            foreach ($landscape->topics as $topic) {
                if ($i >= self::MAX_TOPICS) {
                    $lines[] = '- … truncated';
                    break;
                }
                $lines[] = sprintf(
                    '- %s · %s · mcp=%s · articles=%d · dna=%d · %s',
                    $topic->name,
                    $topic->coverage,
                    (string) $topic->mcp,
                    $topic->articleCount,
                    $topic->dnaCount,
                    $topic->status,
                );
                $i++;
            }
        } catch (Throwable $e) {
            $lines[] = '- Unavailable: '.mb_substr($e->getMessage(), 0, 160);
        }

        return implode("\n", $lines);
    }

    private function renderGscSection(int $siteId, string $periodKey): string
    {
        $lines = ['## GSC Intelligence', ''];
        try {
            $gsc = $this->gsc->forSite($siteId, $periodKey);
            if (! $gsc->available()) {
                $reason = trim((string) ($gsc->metrics['absent_reason'] ?? ''));
                $lines[] = '- Status: unavailable for period '.$periodKey;
                if ($reason !== '') {
                    $lines[] = '- Reason: '.$reason;
                }
                $lines[] = '- Note: do not invent GSC metrics; treat as absent.';

                return implode("\n", $lines);
            }

            $lines[] = '- Status: available';
            $lines[] = '- Source updated at: '.($gsc->sourceUpdatedAt() ?? 'unknown');
            $metrics = $gsc->metrics;
            foreach (['clicks', 'impressions', 'ctr', 'avg_position', 'position', 'query_count'] as $key) {
                if (array_key_exists($key, $metrics)) {
                    $lines[] = '- '.$key.': '.(is_scalar($metrics[$key]) ? (string) $metrics[$key] : json_encode($metrics[$key]));
                }
            }

            $aiLines = is_array($gsc->context['ai_lines'] ?? null) ? $gsc->context['ai_lines'] : [];
            if ($aiLines !== []) {
                $lines[] = '- Summary lines:';
                foreach (array_slice($aiLines, 0, self::MAX_GSC_LINES) as $line) {
                    if (is_string($line) && trim($line) !== '') {
                        $lines[] = '  - '.trim($line);
                    }
                }
            }

            $planning = is_array($gsc->context['planning_signals'] ?? null) ? $gsc->context['planning_signals'] : [];
            if ($planning !== []) {
                $lines[] = '- Planning signals:';
                foreach (array_slice($planning, 0, self::MAX_GSC_LINES) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $type = (string) ($row['type'] ?? '');
                    $label = trim((string) ($row['label'] ?? $row['query'] ?? ''));
                    if ($type === '' || $label === '') {
                        continue;
                    }
                    $lines[] = '  - ['.$type.'] '.$label;
                }
            }
        } catch (Throwable $e) {
            $lines[] = '- Unavailable: '.mb_substr($e->getMessage(), 0, 160);
            $lines[] = '- Note: do not invent GSC metrics; treat as absent.';
        }

        return implode("\n", $lines);
    }
}
