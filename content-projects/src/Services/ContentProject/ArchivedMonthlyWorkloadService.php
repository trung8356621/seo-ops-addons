<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Canonical archived-only monthly workload for Archived Projects UI + Export month Summary.
 *
 * Domain aggregates from item.site_id (seo_project_tasks.site_id), never project.site_id.
 * Month SoT = execution project month, never archived_at month.
 *
 * @phpstan-type DomainRow array{site_id: int, domain: string, item_count: int}
 * @phpstan-type WriterRow array{user_id: int, writer_name: string, item_count: int}
 */
final class ArchivedMonthlyWorkloadService
{
    public function __construct(
        private readonly ContentProjectMonthlyWorkloadService $workload,
    ) {}

    /**
     * @return array{
     *     month: string,
     *     month_label: string,
     *     byDomain: list<DomainRow>,
     *     byWriter: list<WriterRow>,
     *     total_items: int,
     *     domain_empty: bool,
     *     writer_empty: bool,
     *     domain_max: int,
     *     writer_max: int
     * }
     */
    public function forMonth(CarbonImmutable|Carbon|string|null $month = null): array
    {
        $domainChart = $this->workload->articlesByDomain(
            $month,
            ContentProjectMonthlyWorkloadService::SCOPE_ARCHIVED,
        );
        $writerChart = $this->workload->articlesByWriter(
            $month,
            ContentProjectMonthlyWorkloadService::SCOPE_ARCHIVED,
        );

        $byDomain = [];
        foreach ($domainChart['rows'] as $row) {
            $byDomain[] = [
                'site_id' => (int) ($row['site_id'] ?? 0),
                'domain' => (string) ($row['domain'] ?? ''),
                'item_count' => (int) ($row['total_count'] ?? $row['count'] ?? 0),
            ];
        }

        $byWriter = [];
        foreach ($writerChart['rows'] as $row) {
            $byWriter[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'writer_name' => (string) ($row['name'] ?? ''),
                'item_count' => (int) ($row['total_count'] ?? $row['count'] ?? 0),
            ];
        }

        $totalItems = 0;
        foreach ($byWriter as $row) {
            $totalItems += $row['item_count'];
        }

        return [
            'month' => (string) ($domainChart['month'] ?? ''),
            'month_label' => (string) ($domainChart['month_label'] ?? ''),
            'byDomain' => $byDomain,
            'byWriter' => $byWriter,
            'total_items' => $totalItems,
            'domain_empty' => (bool) ($domainChart['empty'] ?? $byDomain === []),
            'writer_empty' => (bool) ($writerChart['empty'] ?? $byWriter === []),
            'domain_max' => (int) ($domainChart['max'] ?? 1),
            'writer_max' => (int) ($writerChart['max'] ?? 1),
        ];
    }

    /**
     * @return array{month: string, month_label: string, rows: list<array<string, mixed>>, max: int, empty: bool}
     */
    public function articlesByDomainChart(CarbonImmutable|Carbon|string|null $month = null): array
    {
        return $this->workload->articlesByDomain(
            $month,
            ContentProjectMonthlyWorkloadService::SCOPE_ARCHIVED,
        );
    }

    /**
     * @return array{month: string, month_label: string, rows: list<array<string, mixed>>, max: int, empty: bool}
     */
    public function articlesByWriterChart(CarbonImmutable|Carbon|string|null $month = null): array
    {
        return $this->workload->articlesByWriter(
            $month,
            ContentProjectMonthlyWorkloadService::SCOPE_ARCHIVED,
        );
    }
}
