<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\Seo\Support\ExcelFormulaEscaper;
use App\Support\RuntimeLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ContentProjectArchiveExportService
{
    /** @var list<string> */
    private const OVERVIEW_METADATA_KEYS = [
        'project_name',
        'domain',
        'project_month',
        'project_year',
        'owner',
        'total_articles',
        'completed_articles',
        'approved_articles',
        'synced_articles',
        'average_seo_score',
        'created_at',
        'archived_at',
        'archived_by',
        'note',
    ];

    /** @var list<string> */
    private const EXCLUDED_SNAPSHOT_KEYS = [
        'body',
        'content',
        'html',
        'raw_content',
        'compiled_prompt',
        'blocks',
        'excerpt',
        'description',
    ];

    /** @var array<string, string> */
    private const ARTICLE_LIST_COLUMNS = [
        'position' => 'STT',
        'title' => 'Tiêu đề',
        'slug' => 'Slug',
        'primary_keyword' => 'Từ khóa chính',
        'status' => 'Trạng thái',
        'word_count' => 'Số từ',
        'image_count' => 'Số ảnh',
        'seo_score' => 'SEO score',
        'sync_status' => 'Sync status',
        'wordpress_post_id' => 'WordPress post ID',
        'wordpress_url' => 'WordPress URL',
        'indexed_at' => 'Index gần nhất',
        'previous_indexed_at' => 'Index lần trước',
        'created_at' => 'Ngày tạo',
        'completed_at' => 'Ngày hoàn thành',
        'last_saved_at' => 'Lần cuối lưu',
    ];

    public function download(SeoProjectArchive $archive): StreamedResponse|Response
    {
        return $this->streamDownload($archive);
    }

    public function streamDownload(SeoProjectArchive $archive): StreamedResponse
    {
        $archive = $this->loadArchive($archive);
        $filename = $this->buildFilename($archive);

        return new StreamedResponse(
            function () use ($archive): void {
                $this->writeWorkbookToOutput($archive);
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'max-age=0',
            ],
        );
    }

    private function loadArchive(SeoProjectArchive $archive): SeoProjectArchive
    {
        $archive->load([
            'items' => static fn ($query) => $query->orderBy('position')->orderBy('id'),
            'items.article',
            'items.task',
            'archivedByUser',
            'owner',
            'site',
            'project',
        ]);

        return $archive;
    }

    private function writeWorkbookToOutput(SeoProjectArchive $archive): void
    {
        RuntimeLogger::info('content_project_archive_exported', [
            'project_id' => (int) ($archive->project_id ?? 0),
            'archive_id' => (int) $archive->getKey(),
            'user_id' => auth()->id(),
            'total_articles' => (int) ($archive->total_articles ?? $archive->articles_count ?? 0),
            'domain_id' => (int) ($archive->site_id ?? 0),
        ]);

        $options = new Options();
        $this->tryApplyOptionsFreeze($options);

        $writer = new Writer($options);
        $writer->openToFile('php://output');

        $headerStyle = (new Style())->setFontBold();

        $this->writeOverviewSheet($writer, $archive, $headerStyle);
        $this->writeArticleListSheet($writer, $archive, $headerStyle);
        $this->writeSeoAuditSheet($writer, $archive, $headerStyle);
        $this->writeWordPressSyncSheet($writer, $archive, $headerStyle);

        $writer->close();
    }

    private function writeOverviewSheet(Writer $writer, SeoProjectArchive $archive, Style $headerStyle): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Tổng quan');
        $this->applySheetFreeze($sheet, 2);

        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow(['Trường', 'Giá trị']),
            $headerStyle,
        ));

        foreach ($this->buildOverviewRows($archive) as [$label, $value]) {
            $writer->addRow(Row::fromValues(
                ExcelFormulaEscaper::escapeRow([$label, $this->stringifyCellValue($value)]),
            ));
        }
    }

    private function writeArticleListSheet(Writer $writer, SeoProjectArchive $archive, Style $headerStyle): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Danh sách bài viết');
        $this->applySheetFreeze($sheet, 2);

        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow(array_values(self::ARTICLE_LIST_COLUMNS)),
            $headerStyle,
        ));

        foreach ($archive->items as $item) {
            if (! $item instanceof SeoProjectArchiveItem) {
                continue;
            }

            $data = $this->overlayManualIndexFields(
                $this->resolveArticleData($item),
                $item,
            );
            $row = [];

            foreach (array_keys(self::ARTICLE_LIST_COLUMNS) as $column) {
                $row[] = $this->stringifyCellValue($this->articleField($data, $item, $column));
            }

            $writer->addRow(Row::fromValues(ExcelFormulaEscaper::escapeRow($row)));
        }
    }

    private function writeSeoAuditSheet(Writer $writer, SeoProjectArchive $archive, Style $headerStyle): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('SEO Audit');
        $this->applySheetFreeze($sheet, 2);

        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow(['Tiêu đề', 'Điểm SEO', 'Vi phạm SEO']),
            $headerStyle,
        ));

        foreach ($archive->items as $item) {
            if (! $item instanceof SeoProjectArchiveItem) {
                continue;
            }

            $data = $this->resolveArticleData($item);
            $title = $this->articleField($data, $item, 'title');
            $seoScore = $this->articleField($data, $item, 'seo_score');
            $violations = $this->articleField($data, $item, 'seo_rule_violations');

            if ($title === null && $seoScore === null && $violations === null) {
                continue;
            }

            $writer->addRow(Row::fromValues(ExcelFormulaEscaper::escapeRow([
                $this->stringifyCellValue($title),
                $this->stringifyCellValue($seoScore),
                $this->stringifyCellValue($this->formatViolations($violations)),
            ])));
        }
    }

    private function writeWordPressSyncSheet(Writer $writer, SeoProjectArchive $archive, Style $headerStyle): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Đồng bộ WordPress');
        $this->applySheetFreeze($sheet, 2);

        $writer->addRow(Row::fromValues(
            ExcelFormulaEscaper::escapeRow([
                'Tiêu đề',
                'Trạng thái đồng bộ',
                'WordPress Post ID',
                'WordPress URL',
                'Đồng bộ lần cuối',
                'Lỗi đồng bộ',
            ]),
            $headerStyle,
        ));

        foreach ($archive->items as $item) {
            if (! $item instanceof SeoProjectArchiveItem) {
                continue;
            }

            $data = $this->resolveArticleData($item);
            $title = $this->articleField($data, $item, 'title');
            $syncStatus = $this->articleField($data, $item, 'sync_status');
            $wpPostId = $this->articleField($data, $item, 'wordpress_post_id');
            $wpUrl = $this->articleField($data, $item, 'wordpress_url');
            $lastSyncedAt = $this->articleField($data, $item, 'last_synced_at');
            $syncError = $this->articleField($data, $item, 'wp_sync_error');

            if ($title === null
                && $syncStatus === null
                && $wpPostId === null
                && $wpUrl === null
                && $lastSyncedAt === null
                && $syncError === null) {
                continue;
            }

            $writer->addRow(Row::fromValues(ExcelFormulaEscaper::escapeRow([
                $this->stringifyCellValue($title),
                $this->stringifyCellValue($syncStatus),
                $this->stringifyCellValue($wpPostId),
                $this->stringifyCellValue($wpUrl),
                $this->stringifyCellValue($lastSyncedAt),
                $this->stringifyCellValue($syncError),
            ])));
        }
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private function buildOverviewRows(SeoProjectArchive $archive): array
    {
        $summary = is_array($archive->summary_snapshot) ? $archive->summary_snapshot : [];
        $usedKeys = [];

        $rows = [
            ['Tên dự án', $this->firstNonEmpty([
                $archive->project_name,
                $summary['project_name'] ?? null,
            ])],
            ['Domain', $this->resolveDomain($archive)],
            ['Tháng', $this->firstNonEmpty([
                $archive->project_month,
                $summary['month'] ?? null,
                $summary['project_month'] ?? null,
            ])],
            ['Năm', $this->firstNonEmpty([
                $archive->project_year,
                $summary['year'] ?? null,
                $summary['project_year'] ?? null,
            ])],
            ['Chủ sở hữu', $this->resolveOwnerLabel($archive, $summary)],
            ['Tổng bài viết', $this->firstNonEmpty([
                $archive->total_articles,
                $archive->articles_count,
                $summary['total_articles'] ?? null,
            ])],
            ['Hoàn thành', $this->firstNonEmpty([
                $archive->completed_articles,
                $summary['completed_articles'] ?? null,
            ])],
            ['Đã duyệt', $this->firstNonEmpty([
                $archive->approved_articles,
                $summary['approved_articles'] ?? null,
            ])],
            ['Đã đồng bộ', $this->firstNonEmpty([
                $archive->synced_articles,
                $summary['synced_articles'] ?? null,
            ])],
            ['SEO trung bình', $this->firstNonEmpty([
                $archive->average_seo_score,
                $summary['average_seo_score'] ?? null,
            ])],
            ['Ngày tạo', $this->formatDateTime($archive->created_at)],
            ['Ngày lưu trữ', $this->formatDateTime($this->firstNonEmpty([
                $archive->archived_at,
                $summary['archived_at'] ?? null,
            ]))],
            ['Lưu trữ bởi', $this->resolveArchivedByLabel($archive, $summary)],
            ['Ghi chú', $this->firstNonEmpty([
                $archive->note,
                $summary['note'] ?? null,
            ])],
        ];

        foreach ([
            ...self::OVERVIEW_METADATA_KEYS,
            'domain_name',
            'month',
            'year',
            'owner_name',
            'failed_articles',
            'incomplete_articles',
            'unapproved_articles',
            'unsynced_articles',
            'project_id',
            'domain_id',
        ] as $key) {
            $usedKeys[$key] = true;
        }

        foreach ($summary as $key => $value) {
            if (! is_string($key) || isset($usedKeys[$key]) || $this->shouldSkipSnapshotKey($key)) {
                continue;
            }

            $rows[] = [$this->humanizeSnapshotKey($key), $value];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveArticleData(SeoProjectArchiveItem $item): array
    {
        $snapshot = is_array($item->article_snapshot) ? $item->article_snapshot : [];
        if ($snapshot !== []) {
            return $snapshot;
        }

        $article = $item->article;
        if ($article === null) {
            return [];
        }

        return array_filter([
            'position' => $item->position,
            'article_id' => $article->getKey(),
            'title' => $article->title ?? null,
            'status' => $item->task?->status,
            'approved_status' => $article->review_status ?? null,
            'seo_score' => $article->seoProfile?->seo_score ?? null,
            'completed_at' => $this->formatDateTime($item->task?->completed_at ?? $article->reviewed_at),
            'sync_status' => $article->wordpressLink?->sync_status ?? null,
            'wordpress_post_id' => $article->wordpressLink?->wp_post_id ?? null,
            'last_synced_at' => $this->formatDateTime($article->wordpressLink?->last_synced_at),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function articleField(array $data, SeoProjectArchiveItem $item, string $field): mixed
    {
        if (array_key_exists($field, $data)) {
            return $data[$field];
        }

        return match ($field) {
            'position' => $item->position,
            'article_id' => $item->article_id,
            'title' => $item->article?->title,
            'primary_keyword' => null,
            'status' => $item->task?->status,
            'approved_status' => $item->article?->review_status,
            'seo_score' => $item->article?->seoProfile?->seo_score,
            'completed_at' => $this->formatDateTime($item->task?->completed_at ?? $item->article?->reviewed_at),
            'seo_rule_violations' => null,
            'sync_status' => $item->article?->wordpressLink?->sync_status ?? null,
            'wordpress_post_id' => $item->article?->wordpressLink?->wp_post_id,
            'wordpress_url' => null,
            'indexed_at' => $this->formatDateTime($item->article?->seoProfile?->indexed_at),
            'previous_indexed_at' => $this->formatDateTime($item->article?->seoProfile?->previous_indexed_at),
            'last_synced_at' => $this->formatDateTime($item->article?->wordpressLink?->last_synced_at),
            'wp_sync_error' => null,
            default => null,
        };
    }

    /**
     * Prefer live article timestamps when snapshot thiếu (marker sau archive).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function overlayManualIndexFields(array $data, SeoProjectArchiveItem $item): array
    {
        $article = $item->article;

        $indexed = $data['indexed_at'] ?? null;
        if (($indexed === null || $indexed === '') && $article?->seoProfile?->indexed_at) {
            $indexed = $article->seoProfile->indexed_at;
        }

        $previous = $data['previous_indexed_at'] ?? null;
        if (($previous === null || $previous === '') && $article?->seoProfile?->previous_indexed_at) {
            $previous = $article->seoProfile->previous_indexed_at;
        }

        $data['indexed_at'] = $this->formatDateTime($indexed);
        $data['previous_indexed_at'] = $this->formatDateTime($previous);

        return $data;
    }

    private function resolveDomain(SeoProjectArchive $archive): string
    {
        $summary = is_array($archive->summary_snapshot) ? $archive->summary_snapshot : [];

        $domain = $this->firstNonEmpty([
            $summary['domain_name'] ?? null,
            $summary['domain'] ?? null,
            $summary['site_domain'] ?? null,
            $archive->site?->domain,
        ]);

        return is_string($domain) ? trim($domain) : '';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function resolveOwnerLabel(SeoProjectArchive $archive, array $summary): ?string
    {
        return $this->firstNonEmpty([
            $summary['owner_name'] ?? null,
            $summary['owner'] ?? null,
            $archive->owner?->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function resolveArchivedByLabel(SeoProjectArchive $archive, array $summary): ?string
    {
        return $this->firstNonEmpty([
            $summary['archived_by_name'] ?? null,
            $archive->archivedByUser?->name,
        ]);
    }

    private function buildFilename(SeoProjectArchive $archive): string
    {
        $domain = $this->resolveDomain($archive);
        $slug = Str::slug($domain !== '' ? $domain : 'domain');
        if ($slug === '') {
            $slug = 'domain';
        }

        $year = (int) ($archive->project_year ?? 0);
        $month = (int) ($archive->project_month ?? 0);
        $period = ($year > 0 && $month > 0)
            ? sprintf('%04d-%02d', $year, $month)
            : ($archive->created_at instanceof Carbon
                ? $archive->created_at->format('Y-m')
                : now()->format('Y-m'));

        return 'content-project-'.$slug.'-'.$period.'.xlsx';
    }

    private function formatViolations(mixed $violations): string
    {
        if ($violations === null) {
            return '';
        }

        if (is_string($violations)) {
            return trim($violations);
        }

        if (! is_array($violations)) {
            return '';
        }

        $parts = [];
        foreach ($violations as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);

                continue;
            }

            if (is_array($value)) {
                $message = trim((string) ($value['message'] ?? $value['rule'] ?? $value['code'] ?? ''));
                if ($message !== '') {
                    $parts[] = $message;

                    continue;
                }
            }

            if (is_string($key) && $value !== null && $value !== '') {
                $parts[] = $key.': '.$this->stringifyCellValue($value);
            }
        }

        return implode('; ', array_values(array_unique(array_filter($parts))));
    }

    private function stringifyCellValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return is_scalar($value) ? (string) $value : null;
        }
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function shouldSkipSnapshotKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::EXCLUDED_SNAPSHOT_KEYS, true)) {
            return true;
        }

        return str_contains($normalized, 'html')
            || str_contains($normalized, 'body')
            || str_contains($normalized, 'content');
    }

    private function humanizeSnapshotKey(string $key): string
    {
        return Str::of($key)->replace('_', ' ')->title()->toString();
    }

    private function tryApplyOptionsFreeze(Options $options): void
    {
        try {
            if (defined(Options::class.'::FREEZE_ROWS')) {
                $constant = constant(Options::class.'::FREEZE_ROWS');
                if (is_string($constant) && property_exists($options, $constant)) {
                    $options->{$constant} = 1;
                }
            }

            if (method_exists($options, 'setFreezeRow')) {
                $options->setFreezeRow(1);
            }
        } catch (\Throwable) {
            // skip freeze silently
        }
    }

    private function applySheetFreeze(mixed $sheet, int $freezeRow): void
    {
        if (! is_object($sheet) || ! method_exists($sheet, 'setSheetView')) {
            return;
        }

        if (! class_exists(SheetView::class)) {
            return;
        }

        try {
            $sheetView = new SheetView();
            if (method_exists($sheetView, 'setFreezeRow')) {
                $sheetView->setFreezeRow($freezeRow);
            }

            $sheet->setSheetView($sheetView);
        } catch (\Throwable) {
            // skip freeze silently
        }
    }
}
