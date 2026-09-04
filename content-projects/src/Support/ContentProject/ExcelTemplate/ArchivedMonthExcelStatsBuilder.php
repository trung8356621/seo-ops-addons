<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Carbon\CarbonImmutable;

/**
 * Builds STATS blocks from the same archived-month export dataset (no duplicate business rules).
 */
final class ArchivedMonthExcelStatsBuilder
{
    public function __construct(
        private readonly ?ContentProjectArchivedMonthlyWorkloadService $workload = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  month export payload (writer_sheets, …)
     */
    public function build(array $payload, bool $includeHistory = true): ArchivedMonthExcelStatsDocument
    {
        $articles = $this->flattenArticles($payload);
        $indexedLabel = (string) __('seo-content-ai::filament.projects.indexed');
        $notIndexedLabel = (string) __('seo-content-ai::filament.projects.not_indexed');
        $planCreate = (string) __('seo-content-ai::filament.projects.archive_export_plan_create');
        $planRewrite = (string) __('seo-content-ai::filament.projects.archive_export_plan_rewrite');
        $planImprove = (string) __('seo-content-ai::filament.projects.archive_export_plan_improve');

        $capacityByWriter = $this->capacityByWriterName($payload);

        $summary = $this->buildSummary($articles, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove);
        $byWriter = $this->buildByWriter($articles, $capacityByWriter, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove);
        $byDomain = $this->buildByDomain($articles, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove);
        $byType = $this->buildByType($articles, $planCreate, $planRewrite, $planImprove);
        $byStatus = $this->buildByStatus($articles, $indexedLabel, $notIndexedLabel);

        $blocks = [
            [
                'id' => ArchivedMonthExcelStatsDocument::BLOCK_SUMMARY,
                'title' => 'metric | label | value',
                'rows' => $summary,
            ],
            [
                'id' => ArchivedMonthExcelStatsDocument::BLOCK_BY_WRITER,
                'title' => (string) __('seo-content-ai::filament.projects.chart_articles_by_writer'),
                'rows' => $byWriter,
            ],
            [
                'id' => ArchivedMonthExcelStatsDocument::BLOCK_BY_DOMAIN,
                'title' => (string) __('seo-content-ai::filament.projects.chart_articles_by_domain'),
                'rows' => $byDomain,
            ],
            [
                'id' => ArchivedMonthExcelStatsDocument::BLOCK_BY_TYPE,
                'title' => (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_type_label'),
                'rows' => $byType,
            ],
            [
                'id' => ArchivedMonthExcelStatsDocument::BLOCK_BY_STATUS,
                'title' => (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_status_label'),
                'rows' => $byStatus,
            ],
        ];

        if ($includeHistory && $this->workload !== null) {
            $monthKey = (string) ($payload['month'] ?? ContentProjectMonthContext::current());
            $blocks[] = [
                'id' => ArchivedMonthExcelStatsDocument::BLOCK_BY_MONTH,
                'title' => (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_month_label'),
                'rows' => $this->buildMonthlySeries($monthKey, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove),
            ];
            $blocks[] = [
                'id' => ArchivedMonthExcelStatsDocument::BLOCK_BY_WEEK,
                'title' => (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_week_label'),
                'rows' => $this->buildWeeklySeries($monthKey, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove),
            ];
        } else {
            $blocks[] = [
                'id' => ArchivedMonthExcelStatsDocument::BLOCK_BY_MONTH,
                'title' => (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_month_label'),
                'rows' => $this->buildSingleMonthSeries($payload, $articles, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove),
            ];
            $blocks[] = [
                'id' => ArchivedMonthExcelStatsDocument::BLOCK_BY_WEEK,
                'title' => (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_week_label'),
                'rows' => $this->buildWeeklyFromArticles($articles, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove),
            ];
        }

        $blocks[] = [
            'id' => ArchivedMonthExcelStatsDocument::BLOCK_FIELD_DICTIONARY,
            'title' => 'Field | Ý nghĩa',
            'rows' => $this->fieldDictionary(),
        ];

        return new ArchivedMonthExcelStatsDocument($blocks);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{writer_name: string, domain: string, plan: string, index_status: string, reviewed_at: string, project: string}>
     */
    private function flattenArticles(array $payload): array
    {
        $articles = [];
        foreach ($payload['writer_sheets'] ?? [] as $sheet) {
            if (! is_array($sheet)) {
                continue;
            }
            $writerName = (string) ($sheet['writer_name'] ?? '');
            foreach ($sheet['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['row_kind'] ?? '') === 'social') {
                    continue;
                }
                $articles[] = [
                    'writer_name' => $writerName,
                    'domain' => (string) ($row['domain'] ?? ''),
                    'plan' => (string) ($row['plan'] ?? ''),
                    'index_status' => (string) ($row['index_status'] ?? ''),
                    'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                    'project' => (string) ($row['project'] ?? ''),
                ];
            }
        }

        return $articles;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array{capacity: int, total: int}>
     */
    private function capacityByWriterName(array $payload): array
    {
        $map = [];
        if ($this->workload === null) {
            return $map;
        }
        try {
            $month = (string) ($payload['month'] ?? '');
            $chart = $this->workload->articlesByWriter($month !== '' ? $month : null);
            foreach ($chart['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $map[$name] = [
                    'capacity' => (int) ($row['capacity'] ?? 0),
                    'total' => (int) ($row['count'] ?? $row['total_count'] ?? 0),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $map;
    }

    /**
     * @param  list<array{writer_name: string, domain: string, plan: string, index_status: string, reviewed_at: string, project: string}>  $articles
     * @return list<list<scalar|null>>
     */
    private function buildSummary(
        array $articles,
        string $indexedLabel,
        string $notIndexedLabel,
        string $planCreate,
        string $planRewrite,
        string $planImprove,
    ): array {
        $agg = $this->aggregateBucket($articles, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove);
        $rows = [
            ['metric', 'label', 'value'],
            ['articles_total', 'Tổng số bài', $agg['total']],
            ['articles_archived', 'Bài lưu trữ', $agg['total']],
            ['articles_indexed', 'Bài đã index', $agg['indexed']],
            ['articles_not_indexed', 'Bài chưa index', $agg['not_indexed']],
            ['index_rate', 'Tỷ lệ index (%)', $agg['index_rate']],
            ['articles_new', 'Viết mới', $agg['new']],
            ['articles_rewrite', 'Viết lại', $agg['rewrite']],
            ['articles_improve', 'Cải thiện', $agg['improve']],
        ];

        return $rows;
    }

    /**
     * @param  list<array{writer_name: string, domain: string, plan: string, index_status: string, reviewed_at: string, project: string}>  $articles
     * @param  array<string, array{capacity: int, total: int}>  $capacityByWriter
     * @return list<list<scalar|null>>
     */
    private function buildByWriter(
        array $articles,
        array $capacityByWriter,
        string $indexedLabel,
        string $notIndexedLabel,
        string $planCreate,
        string $planRewrite,
        string $planImprove,
    ): array {
        $groups = [];
        foreach ($articles as $article) {
            $key = $article['writer_name'] !== '' ? $article['writer_name'] : 'Unknown';
            $groups[$key][] = $article;
        }

        $header = ['writer', 'total', 'indexed', 'not_indexed', 'index_rate', 'new', 'rewrite', 'improve', 'kpi_assigned', 'kpi_done', 'kpi_rate'];
        $rows = [$header];
        foreach ($groups as $name => $bucket) {
            $agg = $this->aggregateBucket($bucket, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove);
            $kpiAssigned = (int) ($capacityByWriter[$name]['capacity'] ?? 0);
            $kpiDone = $agg['total'];
            $kpiRate = $kpiAssigned > 0 ? round(($kpiDone / $kpiAssigned) * 100, 1) : null;
            $rows[] = [
                $name,
                $agg['total'],
                $agg['indexed'],
                $agg['not_indexed'],
                $agg['index_rate'],
                $agg['new'],
                $agg['rewrite'],
                $agg['improve'],
                $kpiAssigned > 0 ? $kpiAssigned : null,
                $kpiDone,
                $kpiRate,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{writer_name: string, domain: string, plan: string, index_status: string, reviewed_at: string, project: string}>  $articles
     * @return list<list<scalar|null>>
     */
    private function buildByDomain(
        array $articles,
        string $indexedLabel,
        string $notIndexedLabel,
        string $planCreate,
        string $planRewrite,
        string $planImprove,
    ): array {
        $groups = [];
        foreach ($articles as $article) {
            $key = $article['domain'] !== '' ? $article['domain'] : '(unresolved)';
            $groups[$key][] = $article;
        }

        $header = ['domain', 'total', 'indexed', 'not_indexed', 'index_rate', 'new', 'rewrite', 'improve'];
        $rows = [$header];
        foreach ($groups as $name => $bucket) {
            $agg = $this->aggregateBucket($bucket, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove);
            $rows[] = [
                $name,
                $agg['total'],
                $agg['indexed'],
                $agg['not_indexed'],
                $agg['index_rate'],
                $agg['new'],
                $agg['rewrite'],
                $agg['improve'],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{writer_name: string, domain: string, plan: string, index_status: string, reviewed_at: string, project: string}>  $articles
     * @return list<list<scalar|null>>
     */
    private function buildByType(
        array $articles,
        string $planCreate,
        string $planRewrite,
        string $planImprove,
    ): array {
        $counts = [
            $planCreate => 0,
            $planRewrite => 0,
            $planImprove => 0,
        ];
        foreach ($articles as $article) {
            $plan = $article['plan'];
            if (! isset($counts[$plan])) {
                $counts[$plan] = 0;
            }
            $counts[$plan]++;
        }

        $rows = [['type', 'total']];
        foreach ($counts as $type => $total) {
            if ($total <= 0 && ! in_array($type, [$planCreate, $planRewrite, $planImprove], true)) {
                continue;
            }
            $rows[] = [$type, $total];
        }

        return $rows;
    }

    /**
     * @param  list<array{writer_name: string, domain: string, plan: string, index_status: string, reviewed_at: string, project: string}>  $articles
     * @return list<list<scalar|null>>
     */
    private function buildByStatus(array $articles, string $indexedLabel, string $notIndexedLabel): array
    {
        $counts = [
            $indexedLabel => 0,
            $notIndexedLabel => 0,
        ];
        foreach ($articles as $article) {
            $status = $article['index_status'];
            if ($status === '') {
                continue;
            }
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $rows = [['status', 'total']];
        foreach ($counts as $status => $total) {
            $rows[] = [$status, $total];
        }

        return $rows;
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function buildMonthlySeries(
        string $anchorMonth,
        string $indexedLabel,
        string $notIndexedLabel,
        string $planCreate,
        string $planRewrite,
        string $planImprove,
    ): array {
        $header = ['month_key', 'month_label', 'total', 'indexed', 'not_indexed', 'index_rate', 'new', 'rewrite', 'improve'];
        $rows = [$header];
        $end = CarbonImmutable::createFromFormat('Y-m', ContentProjectMonthContext::normalize($anchorMonth))?->startOfMonth()
            ?? CarbonImmutable::now()->startOfMonth();

        for ($i = 11; $i >= 0; $i--) {
            $month = $end->subMonths($i)->format('Y-m');
            $payload = $this->miniPayloadForMonth($month);
            $articles = $this->flattenArticles($payload);
            if ($articles === [] && $i > 0) {
                // keep empty months in series only when later months may have data — still emit zeros for continuity
            }
            $agg = $this->aggregateBucket($articles, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove);
            $rows[] = [
                $month,
                ContentProjectMonthContext::display($month),
                $agg['total'],
                $agg['indexed'],
                $agg['not_indexed'],
                $agg['index_rate'],
                $agg['new'],
                $agg['rewrite'],
                $agg['improve'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{writer_name: string, domain: string, plan: string, index_status: string, reviewed_at: string, project: string}>  $articles
     * @return list<list<scalar|null>>
     */
    private function buildSingleMonthSeries(
        array $payload,
        array $articles,
        string $indexedLabel,
        string $notIndexedLabel,
        string $planCreate,
        string $planRewrite,
        string $planImprove,
    ): array {
        $month = (string) ($payload['month'] ?? '');
        $label = (string) ($payload['month_label'] ?? ContentProjectMonthContext::display($month));
        $agg = $this->aggregateBucket($articles, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove);

        return [
            ['month_key', 'month_label', 'total', 'indexed', 'not_indexed', 'index_rate', 'new', 'rewrite', 'improve'],
            [$month, $label, $agg['total'], $agg['indexed'], $agg['not_indexed'], $agg['index_rate'], $agg['new'], $agg['rewrite'], $agg['improve']],
        ];
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function buildWeeklySeries(
        string $anchorMonth,
        string $indexedLabel,
        string $notIndexedLabel,
        string $planCreate,
        string $planRewrite,
        string $planImprove,
    ): array {
        $end = CarbonImmutable::createFromFormat('Y-m', ContentProjectMonthContext::normalize($anchorMonth))?->endOfMonth()
            ?? CarbonImmutable::now();
        $articles = [];
        for ($i = 0; $i < 4; $i++) {
            $month = $end->subMonths($i)->format('Y-m');
            $articles = array_merge($articles, $this->flattenArticles($this->miniPayloadForMonth($month)));
        }

        return $this->buildWeeklyFromArticles($articles, $indexedLabel, $notIndexedLabel, $planCreate, $planRewrite, $planImprove);
    }

    /**
     * @param  list<array{writer_name: string, domain: string, plan: string, index_status: string, reviewed_at: string, project: string}>  $articles
     * @return list<list<scalar|null>>
     */
    private function buildWeeklyFromArticles(
        array $articles,
        string $indexedLabel,
        string $notIndexedLabel,
        string $planCreate,
        string $planRewrite,
        string $planImprove,
    ): array {
        $groups = [];
        foreach ($articles as $article) {
            $reviewed = trim($article['reviewed_at']);
            if ($reviewed === '') {
                continue;
            }
            try {
                $dt = CarbonImmutable::parse($reviewed);
            } catch (\Throwable) {
                continue;
            }
            $key = $dt->isoFormat('GGGG-[W]WW');
            $groups[$key]['articles'][] = $article;
            $groups[$key]['start'] = $dt->startOfWeek()->format('Y-m-d');
            $groups[$key]['end'] = $dt->endOfWeek()->format('Y-m-d');
            $groups[$key]['label'] = 'T'.$dt->isoWeek.'/'.$dt->isoWeekYear;
        }

        ksort($groups);
        $groups = array_slice($groups, -12, 12, true);

        $rows = [['week_key', 'week_label', 'week_start', 'week_end', 'total', 'indexed', 'not_indexed', 'new', 'rewrite', 'improve']];
        foreach ($groups as $key => $group) {
            $agg = $this->aggregateBucket(
                $group['articles'],
                $indexedLabel,
                $notIndexedLabel,
                $planCreate,
                $planRewrite,
                $planImprove,
            );
            $rows[] = [
                $key,
                $group['label'],
                $group['start'],
                $group['end'],
                $agg['total'],
                $agg['indexed'],
                $agg['not_indexed'],
                $agg['new'],
                $agg['rewrite'],
                $agg['improve'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function miniPayloadForMonth(string $month): array
    {
        if ($this->workload === null) {
            return ['writer_sheets' => []];
        }

        try {
            $items = $this->workload->itemRows($month);
        } catch (\Throwable) {
            return ['writer_sheets' => []];
        }

        $siteIds = [];
        foreach ($items as $item) {
            $siteId = (int) ($item['site_id'] ?? 0);
            if ($siteId > 0) {
                $siteIds[$siteId] = $siteId;
            }
        }
        $domains = [];
        try {
            $domains = $this->workload->domainLabels(array_values($siteIds));
        } catch (\Throwable) {
            $domains = [];
        }

        $sheets = [];
        foreach ($items as $item) {
            $writer = (string) ($item['writer_name'] ?? 'Unknown');
            if (! isset($sheets[$writer])) {
                $sheets[$writer] = ['writer_name' => $writer, 'rows' => []];
            }
            $siteId = (int) ($item['site_id'] ?? 0);
            $sheets[$writer]['rows'][] = [
                'domain' => $siteId > 0 ? (string) ($domains[$siteId] ?? '#'.$siteId) : '',
                'plan' => (string) ($item['plan'] ?? ''),
                'index_status' => (string) ($item['index_status'] ?? ''),
                'reviewed_at' => (string) ($item['reviewed_at'] ?? ''),
                'project' => (string) ($item['project_name'] ?? ''),
            ];
        }

        return ['month' => $month, 'writer_sheets' => array_values($sheets)];
    }

    /**
     * @param  list<array{writer_name: string, domain: string, plan: string, index_status: string, reviewed_at: string, project: string}>  $articles
     * @return array{total: int, indexed: int, not_indexed: int, index_rate: float|null, new: int, rewrite: int, improve: int}
     */
    private function aggregateBucket(
        array $articles,
        string $indexedLabel,
        string $notIndexedLabel,
        string $planCreate,
        string $planRewrite,
        string $planImprove,
    ): array {
        $total = count($articles);
        $indexed = 0;
        $notIndexed = 0;
        $new = 0;
        $rewrite = 0;
        $improve = 0;
        foreach ($articles as $article) {
            if ($article['index_status'] === $indexedLabel) {
                $indexed++;
            } elseif ($article['index_status'] === $notIndexedLabel) {
                $notIndexed++;
            }
            if ($article['plan'] === $planCreate) {
                $new++;
            } elseif ($article['plan'] === $planRewrite) {
                $rewrite++;
            } elseif ($article['plan'] === $planImprove) {
                $improve++;
            }
        }

        return [
            'total' => $total,
            'indexed' => $indexed,
            'not_indexed' => $notIndexed,
            'index_rate' => $total > 0 ? round(($indexed / $total) * 100, 1) : null,
            'new' => $new,
            'rewrite' => $rewrite,
            'improve' => $improve,
        ];
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function fieldDictionary(): array
    {
        return [
            ['field', 'meaning'],
            ['total', 'Tổng số bài trong scope'],
            ['indexed', 'Số bài xác định đã index'],
            ['not_indexed', 'Số bài chưa index'],
            ['index_rate', 'indexed / total * 100'],
            ['new', 'Bài viết mới (create)'],
            ['rewrite', 'Viết lại'],
            ['improve', 'Cải thiện'],
            ['kpi_assigned', 'KPI giao (capacity tháng)'],
            ['kpi_done', 'KPI thực hiện (tổng bài)'],
            ['kpi_rate', 'kpi_done / kpi_assigned * 100'],
            ['month_key', 'Khóa tháng máy đọc (YYYY-MM)'],
            ['week_key', 'Khóa tuần ISO (GGGG-Www)'],
        ];
    }
}
