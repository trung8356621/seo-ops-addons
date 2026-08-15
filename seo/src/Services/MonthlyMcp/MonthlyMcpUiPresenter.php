<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Omnichannel\Addons\Content\Support\SystemDateTime;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTag;

final class MonthlyMcpUiPresenter
{
    /**
     * @param  array<string, mixed>  $aiContext
     * @return list<array{title: string, lines: list<string>}>
     */
    public function readableSections(array $aiContext): array
    {
        $site = is_array($aiContext['site'] ?? null) ? $aiContext['site'] : [];
        $kw = is_array($aiContext['keyword_intelligence'] ?? null) ? $aiContext['keyword_intelligence'] : [];
        $metrics = is_array($kw['metrics'] ?? null) ? $kw['metrics'] : [];
        $sections = [];

        $sections[] = [
            'title' => __('seo-content-ai::filament.mcp_intelligence.readable_site'),
            'lines' => array_values(array_filter([
                (string) ($site['domain'] ?? ''),
                __('seo-content-ai::filament.mcp_intelligence.site_health').': '.((string) ($site['health'] ?? 'unknown')),
                __('seo-content-ai::filament.mcp_intelligence.indexability').': '.(int) ($site['indexability_warnings'] ?? 0),
            ])),
        ];

        $sections[] = [
            'title' => __('seo-content-ai::filament.mcp_intelligence.readable_keywords'),
            'lines' => [
                __('seo-content-ai::filament.mcp_intelligence.metric_focus').': '.(int) ($metrics['focus'] ?? 0),
                __('seo-content-ai::filament.mcp_intelligence.metric_error').': '.(int) ($metrics['error'] ?? 0),
                __('seo-content-ai::filament.mcp_intelligence.metric_excluded').': '.(int) ($metrics['excluded'] ?? 0),
                __('seo-content-ai::filament.mcp_intelligence.metric_clusters').': '.(int) ($metrics['clusters'] ?? 0),
                __('seo-content-ai::filament.mcp_intelligence.metric_unclustered').': '.(int) ($metrics['unclustered'] ?? 0),
            ],
        ];

        $sections[] = [
            'title' => __('seo-content-ai::filament.mcp_intelligence.highlights'),
            'lines' => $this->namedLines((array) ($aiContext['highlights'] ?? [])),
        ];
        $sections[] = [
            'title' => __('seo-content-ai::filament.mcp_intelligence.risks'),
            'lines' => $this->namedLines((array) ($aiContext['risks'] ?? [])),
        ];
        $sections[] = [
            'title' => __('seo-content-ai::filament.mcp_intelligence.opportunities'),
            'lines' => $this->namedLines((array) ($aiContext['opportunities'] ?? [])),
        ];
        $sections[] = [
            'title' => __('seo-content-ai::filament.mcp_intelligence.actions'),
            'lines' => $this->namedLines((array) ($aiContext['recommended_actions'] ?? [])),
        ];

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $aiContext
     * @return list<string>
     */
    public function readableLines(array $aiContext): array
    {
        $lines = [];
        foreach ($this->readableSections($aiContext) as $section) {
            $lines[] = $section['title'];
            foreach ($section['lines'] as $line) {
                $lines[] = '- '.$line;
            }
            $lines[] = '';
        }

        return $lines;
    }

    public function freshnessClass(string $freshness): string
    {
        return match ($freshness) {
            'current' => 'mcp-tone-current',
            'stale' => 'mcp-tone-stale',
            'failed' => 'mcp-tone-failed',
            default => 'mcp-tone-missing',
        };
    }

    public function reportStatusClass(string $status): string
    {
        return match ($status) {
            'ready' => 'mcp-tone-current',
            'incomplete', 'updating' => 'mcp-tone-stale',
            'failed' => 'mcp-tone-failed',
            default => 'mcp-tone-missing',
        };
    }

    public function humanWhen(?string $iso): string
    {
        $relative = MonthlyMcpFreshness::relative($iso);
        $absolute = SystemDateTime::formatDateTime($iso);
        if ($relative !== null && $absolute !== null) {
            return $relative.' · '.$absolute;
        }

        return $relative ?? $absolute ?? '—';
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{label: string, url: ?string, module: string}
     */
    public function actionLink(array $item): array
    {
        $key = (string) ($item['key'] ?? '');
        $name = (string) ($item['name'] ?? '');
        $count = (int) ($item['count'] ?? 0);
        $clusterId = (string) ($item['cluster_id'] ?? '');

        return match ($key) {
            'review_keyword_errors' => [
                'module' => 'keyword',
                'label' => __('seo-content-ai::filament.mcp_intelligence.action_review_errors', ['count' => $count]),
                'url' => KeywordResource::buildOperationalTagFilterUrl(KeywordTag::ERROR),
            ],
            'review_unclustered' => [
                'module' => 'cluster',
                'label' => __('seo-content-ai::filament.mcp_intelligence.action_unclustered', ['count' => $count]),
                'url' => KeywordResource::getUrl('clusters'),
            ],
            'expand_cluster' => [
                'module' => 'cluster',
                'label' => __('seo-content-ai::filament.mcp_intelligence.action_expand_cluster', ['name' => $name !== '' ? $name : $clusterId]),
                'url' => $clusterId !== '' ? KeywordResource::getUrl('cluster', ['clusterKey' => $clusterId]) : KeywordResource::getUrl('clusters'),
            ],
            'expand_group' => [
                'module' => 'coverage',
                'label' => __('seo-content-ai::filament.mcp_intelligence.action_expand_group', ['name' => $name]),
                'url' => KeywordResource::getUrl('clusters'),
            ],
            'review_seo_findings' => [
                'module' => 'site',
                'label' => __('seo-content-ai::filament.mcp_intelligence.action_review_findings', ['count' => $count]),
                'url' => null,
            ],
            default => [
                'module' => 'keyword',
                'label' => trim($key.' '.$name),
                'url' => KeywordResource::getUrl('index'),
            ],
        };
    }

    public function highlightText(array $item): string
    {
        $key = (string) ($item['key'] ?? '');
        $name = (string) ($item['name'] ?? $item['group_key'] ?? '');
        $count = (int) ($item['count'] ?? 0);

        return match ($key) {
            'strong_group' => __('seo-content-ai::filament.mcp_intelligence.highlight_group', ['name' => $name, 'count' => $count]),
            'strong_cluster' => __('seo-content-ai::filament.mcp_intelligence.highlight_cluster', ['name' => $name]),
            'keyword_error' => __('seo-content-ai::filament.mcp_intelligence.risk_keyword_error', ['count' => $count]),
            'unclustered_keywords' => __('seo-content-ai::filament.mcp_intelligence.risk_unclustered', ['count' => $count]),
            'seo_findings' => __('seo-content-ai::filament.mcp_intelligence.risk_findings', ['count' => $count]),
            'weak_group' => __('seo-content-ai::filament.mcp_intelligence.opp_weak_group', ['name' => $name, 'count' => $count]),
            'weak_cluster' => __('seo-content-ai::filament.mcp_intelligence.opp_weak_cluster', [
                'name' => $name,
                'keywords' => (int) ($item['keyword_count'] ?? 0),
                'articles' => (int) ($item['article_count'] ?? 0),
            ]),
            default => trim($name !== '' ? $name : $key).($count > 0 ? ' · '.$count : ''),
        };
    }

    public function tagLabel(string $key): string
    {
        return KeywordTag::isKnown($key) ? KeywordTag::label($key) : $key;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private function namedLines(array $items): array
    {
        $lines = [];
        foreach (array_slice($items, 0, 8) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $this->highlightText($item);
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }
}
