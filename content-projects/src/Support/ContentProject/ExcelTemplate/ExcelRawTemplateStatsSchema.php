<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * STATS sheet schema for RAW templates — placeholders only, no live report numbers.
 */
final class ExcelRawTemplateStatsSchema
{
    /**
     * @return list<list<scalar|null>>
     */
    public static function rows(): array
    {
        return [
            ['['.ArchivedMonthExcelStatsDocument::BLOCK_SUMMARY.']'],
            ['Metric', 'Value'],
            ['Tổng bài', '{{articles.total}}'],
            ['Bài lưu trữ', '{{articles.archived}}'],
            ['Đã index', '{{articles.indexed}}'],
            ['Chưa index', '{{articles.not_indexed}}'],
            ['Tỷ lệ index', '{{articles.index_rate}}'],
            ['Tháng', '{{month}}'],
            ['Năm', '{{year}}'],
            ['Số dự án', '{{project.total}}'],
            ['Xuất lúc', '{{export.generated_at}}'],
            [''],
            ['['.ArchivedMonthExcelStatsDocument::BLOCK_BY_WRITER.']'],
            ['{{table.articles_by_writer}}'],
            [''],
            ['['.ArchivedMonthExcelStatsDocument::BLOCK_BY_DOMAIN.']'],
            ['{{table.articles_by_domain}}'],
            [''],
            ['['.ArchivedMonthExcelStatsDocument::BLOCK_BY_TYPE.']'],
            ['{{table.articles_by_type}}'],
            [''],
            ['['.ArchivedMonthExcelStatsDocument::BLOCK_BY_STATUS.']'],
            ['{{table.articles_by_status}}'],
            [''],
            ['['.ArchivedMonthExcelStatsDocument::BLOCK_BY_MONTH.']'],
            ['{{table.articles_by_month}}'],
            [''],
            ['['.ArchivedMonthExcelStatsDocument::BLOCK_BY_WEEK.']'],
            ['{{table.articles_by_week}}'],
            [''],
            ['['.ArchivedMonthExcelStatsDocument::BLOCK_FIELD_DICTIONARY.']'],
            ['field', 'meaning'],
            ['total', 'Tổng số bài trong scope'],
            ['indexed', 'Số bài xác định đã index'],
            ['not_indexed', 'Số bài chưa index'],
            ['index_rate', 'indexed / total * 100'],
            ['new', 'Bài viết mới (create)'],
            ['rewrite', 'Viết lại'],
            ['improve', 'Cải thiện'],
        ];
    }
}
