<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * Builds scalar values + registers default archived-month template variables.
 */
final class ArchivedMonthExcelTemplateVariableFactory
{
    public function buildScalarRegistry(): ExcelScalarVariableRegistry
    {
        $registry = new ExcelScalarVariableRegistry();

        $registry->register(new ExcelScalarVariableDefinition(
            'month',
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_month_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_month_desc'),
        ));
        $registry->register(new ExcelScalarVariableDefinition(
            'year',
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_year_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_year_desc'),
        ));
        $registry->register(new ExcelScalarVariableDefinition(
            'articles.total',
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_total_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_total_desc'),
        ));
        $registry->register(new ExcelScalarVariableDefinition(
            'articles.archived',
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_archived_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_archived_desc'),
        ));
        $registry->register(new ExcelScalarVariableDefinition(
            'articles.indexed',
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_indexed_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_indexed_desc'),
        ));
        $registry->register(new ExcelScalarVariableDefinition(
            'articles.not_indexed',
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_not_indexed_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_not_indexed_desc'),
        ));
        $registry->register(new ExcelScalarVariableDefinition(
            'articles.index_rate',
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_index_rate_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_articles_index_rate_desc'),
        ));
        $registry->register(new ExcelScalarVariableDefinition(
            'project.total',
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_project_total_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_project_total_desc'),
        ));
        $registry->register(new ExcelScalarVariableDefinition(
            'export.generated_at',
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_export_generated_at_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_var_export_generated_at_desc'),
        ));

        return $registry;
    }

    public function buildTableRegistry(): ExcelTableVariableRegistry
    {
        $registry = new ExcelTableVariableRegistry();

        $writerCols = ['writer', 'total', 'indexed', 'not_indexed', 'index_rate', 'new', 'rewrite', 'improve', 'kpi_assigned', 'kpi_done', 'kpi_rate'];
        $domainCols = ['domain', 'total', 'indexed', 'not_indexed', 'index_rate', 'new', 'rewrite', 'improve'];
        $statusCols = ['status', 'total'];
        $typeCols = ['type', 'total'];
        $monthCols = ['month_key', 'month_label', 'total', 'indexed', 'not_indexed', 'index_rate', 'new', 'rewrite', 'improve'];
        $weekCols = ['week_key', 'week_label', 'week_start', 'week_end', 'total', 'indexed', 'not_indexed', 'new', 'rewrite', 'improve'];

        $fromStats = static function (array $context, string $blockId, array $fallbackHeader): array {
            $doc = $context['stats_document'] ?? null;
            if ($doc instanceof ArchivedMonthExcelStatsDocument) {
                $block = $doc->block($blockId);
                if ($block !== null && $block['rows'] !== []) {
                    return $block['rows'];
                }
            }

            return [$fallbackHeader];
        };

        $registry->register(new ExcelTableVariableDefinition(
            'table.articles_by_domain',
            (string) __('seo-content-ai::filament.projects.chart_articles_by_domain'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_domain_desc'),
            $domainCols,
            static fn (array $context): array => $fromStats($context, ArchivedMonthExcelStatsDocument::BLOCK_BY_DOMAIN, $domainCols),
        ));

        $registry->register(new ExcelTableVariableDefinition(
            'table.articles_by_writer',
            (string) __('seo-content-ai::filament.projects.chart_articles_by_writer'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_writer_desc'),
            $writerCols,
            static fn (array $context): array => $fromStats($context, ArchivedMonthExcelStatsDocument::BLOCK_BY_WRITER, $writerCols),
        ));

        $registry->register(new ExcelTableVariableDefinition(
            'table.articles_by_status',
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_status_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_status_desc'),
            $statusCols,
            static fn (array $context): array => $fromStats($context, ArchivedMonthExcelStatsDocument::BLOCK_BY_STATUS, $statusCols),
        ));

        $registry->register(new ExcelTableVariableDefinition(
            'table.articles_by_type',
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_type_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_type_desc'),
            $typeCols,
            static fn (array $context): array => $fromStats($context, ArchivedMonthExcelStatsDocument::BLOCK_BY_TYPE, $typeCols),
        ));

        $registry->register(new ExcelTableVariableDefinition(
            'table.articles_by_plan_type',
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_plan_type_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_plan_type_desc'),
            $typeCols,
            static fn (array $context): array => $fromStats($context, ArchivedMonthExcelStatsDocument::BLOCK_BY_TYPE, $typeCols),
        ));

        $registry->register(new ExcelTableVariableDefinition(
            'table.articles_by_month',
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_month_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_month_desc'),
            $monthCols,
            static fn (array $context): array => $fromStats($context, ArchivedMonthExcelStatsDocument::BLOCK_BY_MONTH, $monthCols),
        ));

        $registry->register(new ExcelTableVariableDefinition(
            'table.articles_by_week',
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_week_label'),
            (string) __('seo-content-ai::filament.projects.excel_tpl_table_by_week_desc'),
            $weekCols,
            static fn (array $context): array => $fromStats($context, ArchivedMonthExcelStatsDocument::BLOCK_BY_WEEK, $weekCols),
        ));

        return $registry;
    }

    /**
     * Derive scalar map + aggregation bags from the existing month export payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     scalars: array<string, scalar|null>,
     *     by_domain: list<array<string, mixed>>,
     *     by_writer: list<array<string, mixed>>,
     *     status_counts: list<array{label: string, count: int}>,
     *     type_counts: list<array{label: string, count: int}>,
     *     week_counts: list<array{label: string, count: int}>,
     *     month_label: string,
     *     articles_total: int,
     *     stats_document: ?ArchivedMonthExcelStatsDocument
     * }
     */
    public function buildContext(array $payload, ?ArchivedMonthExcelStatsDocument $stats = null): array
    {
        $month = (string) ($payload['month'] ?? '');
        $monthLabel = (string) ($payload['month_label'] ?? '');
        $total = (int) ($payload['total_articles'] ?? 0);
        $year = '';
        if (preg_match('/^(\d{4})/', $month, $m) === 1) {
            $year = $m[1];
        } elseif (preg_match('/(\d{4})/', $monthLabel, $m) === 1) {
            $year = $m[1];
        }

        $indexedLabel = (string) __('seo-content-ai::filament.projects.indexed');
        $notIndexedLabel = (string) __('seo-content-ai::filament.projects.not_indexed');

        $indexed = 0;
        $notIndexed = 0;
        $projects = [];
        $statusBag = [];
        $typeBag = [];
        $weekBag = [];

        foreach ($payload['writer_sheets'] ?? [] as $sheet) {
            if (! is_array($sheet)) {
                continue;
            }
            foreach ($sheet['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                if (($row['row_kind'] ?? '') === 'social') {
                    continue;
                }

                $indexStatus = trim((string) ($row['index_status'] ?? ''));
                $plan = trim((string) ($row['plan'] ?? ''));
                $project = trim((string) ($row['project'] ?? ''));
                $reviewedAt = trim((string) ($row['reviewed_at'] ?? ''));

                if ($project !== '') {
                    $projects[$project] = true;
                }

                if ($indexStatus === $indexedLabel) {
                    $indexed++;
                } elseif ($indexStatus === $notIndexedLabel) {
                    $notIndexed++;
                }

                if ($indexStatus !== '') {
                    $statusBag[$indexStatus] = ($statusBag[$indexStatus] ?? 0) + 1;
                }
                if ($plan !== '') {
                    $typeBag[$plan] = ($typeBag[$plan] ?? 0) + 1;
                }

                $weekKey = $this->weekLabelFromReviewedAt($reviewedAt);
                if ($weekKey !== '') {
                    $weekBag[$weekKey] = ($weekBag[$weekKey] ?? 0) + 1;
                }
            }
        }

        arsort($statusBag);
        arsort($typeBag);
        ksort($weekBag);

        return [
            'scalars' => [
                'month' => $monthLabel !== '' ? $monthLabel : $month,
                'year' => $year,
                'articles.total' => $total,
                'articles.archived' => $total,
                'articles.indexed' => $indexed,
                'articles.not_indexed' => $notIndexed,
                'articles.index_rate' => $total > 0 ? round(($indexed / $total) * 100, 1) : null,
                'project.total' => count($projects),
                'export.generated_at' => now()->format('Y-m-d H:i:s'),
            ],
            'by_domain' => is_array($payload['by_domain'] ?? null) ? $payload['by_domain'] : [],
            'by_writer' => is_array($payload['by_writer'] ?? null) ? $payload['by_writer'] : [],
            'status_counts' => $this->bagToCountRows($statusBag),
            'type_counts' => $this->bagToCountRows($typeBag),
            'week_counts' => $this->bagToCountRows($weekBag),
            'month_label' => $monthLabel !== '' ? $monthLabel : $month,
            'articles_total' => $total,
            'stats_document' => $stats,
        ];
    }

    /**
     * @param  list<string>  $header
     * @param  list<array{label: string, count: int}>  $counts
     * @return list<list<scalar|null>>
     */
    private static function aggregateCountTable(array $header, array $counts): array
    {
        $rows = [$header];
        foreach ($counts as $row) {
            $rows[] = [
                (string) ($row['label'] ?? ''),
                (int) ($row['count'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $bag
     * @return list<array{label: string, count: int}>
     */
    private function bagToCountRows(array $bag): array
    {
        $rows = [];
        foreach ($bag as $label => $count) {
            $rows[] = ['label' => (string) $label, 'count' => (int) $count];
        }

        return $rows;
    }

    private function weekLabelFromReviewedAt(string $reviewedAt): string
    {
        if ($reviewedAt === '') {
            return '';
        }

        try {
            $dt = \Carbon\CarbonImmutable::parse($reviewedAt);
        } catch (\Throwable) {
            return '';
        }

        return $dt->isoFormat('GGGG-[W]WW');
    }
}
