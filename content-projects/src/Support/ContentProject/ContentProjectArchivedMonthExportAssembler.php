<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Groups archived execution items for the month workbook.
 *
 * Summary by_domain skips null site_id (matches SQL aggregate).
 * Writer sheets flatten every item of that writer across projects.
 *
 * @phpstan-type ItemInput array{
 *     writer_id: int,
 *     writer_name: string,
 *     project_name: string,
 *     site_id: int|null,
 *     article_id: int,
 *     title: string,
 *     keyword: string,
 *     wordpress_url: string,
 *     post_type: string,
 *     plan: string,
 *     index_status: string,
 *     archived_at: string,
 *     archived_by: string
 * }
 * @phpstan-type DomainRow array{site_id: int, domain: string, item_count: int}
 * @phpstan-type WriterRow array{user_id: int, writer_name: string, item_count: int}
 * @phpstan-type SheetRow array{
 *     project: string,
 *     domain: string,
 *     title: string,
 *     article_id: int,
 *     keyword: string,
 *     wordpress_url: string,
 *     post_type: string,
 *     plan: string,
 *     index_status: string,
 *     archived_at: string,
 *     archived_by: string
 * }
 * @phpstan-type WriterSheet array{user_id: int, writer_name: string, sheet_name: string, rows: list<SheetRow>}
 * @phpstan-type Payload array{
 *     month: string,
 *     month_label: string,
 *     total_articles: int,
 *     unresolved_site_id_count: int,
 *     by_domain: list<DomainRow>,
 *     by_writer: list<WriterRow>,
 *     writer_sheets: list<WriterSheet>
 * }
 */
final class ContentProjectArchivedMonthExportAssembler
{
    public function __construct(
        private readonly ContentProjectItemDomainResolver $domains = new ContentProjectItemDomainResolver(),
    ) {}

    /**
     * @param  list<ItemInput>  $items
     * @param  array<int, string>  $domainsBySiteId
     * @return Payload
     */
    public function assemble(
        string $month,
        string $monthLabel,
        array $items,
        array $domainsBySiteId,
        string $summarySheetName = 'Summary',
    ): array {
        $domainCounts = [];
        $writerCounts = [];
        $writerMeta = [];
        $sheets = [];
        $unresolved = 0;

        foreach ($items as $item) {
            $writerId = (int) ($item['writer_id'] ?? 0);
            $writerName = trim((string) ($item['writer_name'] ?? ''));
            if ($writerName === '') {
                $writerName = $writerId > 0 ? '#'.$writerId : 'Unknown';
            }

            $siteId = isset($item['site_id']) ? (int) $item['site_id'] : 0;
            $siteId = $siteId > 0 ? $siteId : null;
            $domain = $this->domains->label($siteId, $domainsBySiteId);
            if ($this->domains->isUnresolved($domain)) {
                $unresolved++;
            }

            if ($siteId !== null) {
                if (! isset($domainCounts[$siteId])) {
                    $domainCounts[$siteId] = [
                        'site_id' => $siteId,
                        'domain' => $domain,
                        'item_count' => 0,
                    ];
                }
                $domainCounts[$siteId]['item_count']++;
            }

            if ($writerId > 0) {
                if (! isset($writerCounts[$writerId])) {
                    $writerCounts[$writerId] = [
                        'user_id' => $writerId,
                        'writer_name' => $writerName,
                        'item_count' => 0,
                    ];
                    $writerMeta[$writerId] = $writerName;
                }
                $writerCounts[$writerId]['item_count']++;
            }

            $sheetKey = $writerId > 0 ? $writerId : 0;
            if (! isset($sheets[$sheetKey])) {
                $sheets[$sheetKey] = [
                    'user_id' => $writerId,
                    'writer_name' => $writerName,
                    'rows' => [],
                ];
            }

            $sheets[$sheetKey]['rows'][] = [
                'project' => (string) ($item['project_name'] ?? ''),
                'domain' => $domain,
                'title' => (string) ($item['title'] ?? ''),
                'article_id' => (int) ($item['article_id'] ?? 0),
                'keyword' => (string) ($item['keyword'] ?? ''),
                'wordpress_url' => (string) ($item['wordpress_url'] ?? ''),
                'post_type' => (string) ($item['post_type'] ?? ''),
                'plan' => (string) ($item['plan'] ?? ''),
                'index_status' => (string) ($item['index_status'] ?? ''),
                'archived_at' => (string) ($item['archived_at'] ?? ''),
                'archived_by' => (string) ($item['archived_by'] ?? ''),
            ];
        }

        $byDomain = array_values($domainCounts);
        usort($byDomain, static fn (array $a, array $b): int => $b['item_count'] <=> $a['item_count']);

        $byWriter = array_values($writerCounts);
        usort($byWriter, static fn (array $a, array $b): int => $b['item_count'] <=> $a['item_count']);

        $usedLower = [];
        $reservedSummary = trim($summarySheetName);
        ExcelSheetNameSanitizer::unique($reservedSummary !== '' ? $reservedSummary : 'Summary', $usedLower);
        $writerSheets = [];
        foreach ($sheets as $sheet) {
            $writerSheets[] = [
                'user_id' => (int) $sheet['user_id'],
                'writer_name' => (string) $sheet['writer_name'],
                'sheet_name' => ExcelSheetNameSanitizer::unique((string) $sheet['writer_name'], $usedLower),
                'rows' => $sheet['rows'],
            ];
        }

        return [
            'month' => $month,
            'month_label' => $monthLabel,
            'total_articles' => count($items),
            'unresolved_site_id_count' => $unresolved,
            'by_domain' => $byDomain,
            'by_writer' => $byWriter,
            'writer_sheets' => $writerSheets,
        ];
    }
}
