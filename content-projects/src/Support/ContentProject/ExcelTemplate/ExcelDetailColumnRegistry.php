<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * Canonical detail-sheet columns for RAW templates and production mapping.
 */
final class ExcelDetailColumnRegistry
{
    public const CODE_WRITER_NAME = 'writer_name';

    public const CODE_PROJECT_NAME = 'project_name';

    public const CODE_DOMAIN = 'domain';

    public const CODE_ARTICLE_TITLE = 'article_title';

    public const CODE_KEYWORD = 'keyword';

    public const CODE_ARTICLE_TYPE = 'article_type';

    public const CODE_PLAN_TYPE = 'plan_type';

    public const CODE_INDEX_STATUS = 'index_status';

    public const CODE_REVIEWER_NAME = 'reviewer_name';

    public const CODE_ARCHIVED_BY = 'archived_by';

    public const DISPLAY_HEADER_ROW = 1;

    public const SYSTEM_CODE_ROW = 2;

    public const DATA_START_ROW = 3;

    /**
     * @return list<ExcelDetailColumnDefinition>
     */
    public function all(): array
    {
        return [
            new ExcelDetailColumnDefinition(
                self::CODE_WRITER_NAME,
                (string) __('seo-content-ai::filament.projects.archive_export_summary_writer') ?: 'Nhân viên',
                requiredForImport: false,
                dataSheetOnly: true,
            ),
            new ExcelDetailColumnDefinition(
                self::CODE_PROJECT_NAME,
                (string) __('seo-content-ai::filament.projects.archive_export_col_project'),
                requiredForImport: false,
            ),
            new ExcelDetailColumnDefinition(
                self::CODE_DOMAIN,
                (string) __('seo-content-ai::filament.projects.archive_export_col_domain'),
                requiredForImport: false,
            ),
            new ExcelDetailColumnDefinition(
                self::CODE_ARTICLE_TITLE,
                (string) __('seo-content-ai::filament.projects.archive_export_col_article'),
                requiredForImport: false,
            ),
            new ExcelDetailColumnDefinition(
                self::CODE_KEYWORD,
                (string) __('seo-content-ai::filament.projects.archive_export_col_keyword'),
                requiredForImport: false,
            ),
            new ExcelDetailColumnDefinition(
                self::CODE_ARTICLE_TYPE,
                (string) __('seo-content-ai::filament.projects.archive_export_col_post_type'),
                requiredForImport: false,
            ),
            new ExcelDetailColumnDefinition(
                self::CODE_PLAN_TYPE,
                (string) __('seo-content-ai::filament.projects.archive_export_col_plan'),
                requiredForImport: false,
            ),
            new ExcelDetailColumnDefinition(
                self::CODE_INDEX_STATUS,
                (string) __('seo-content-ai::filament.projects.archive_export_col_index'),
                requiredForImport: false,
            ),
            new ExcelDetailColumnDefinition(
                self::CODE_REVIEWER_NAME,
                (string) __('seo-content-ai::filament.projects.archive_export_col_reviewed_at'),
                requiredForImport: false,
            ),
            new ExcelDetailColumnDefinition(
                self::CODE_ARCHIVED_BY,
                (string) __('seo-content-ai::filament.projects.archive_export_col_archived_by'),
                requiredForImport: false,
            ),
        ];
    }

    /**
     * Columns for _WRITER_TEMPLATE / per-writer sheets (no writer_name).
     *
     * @return list<ExcelDetailColumnDefinition>
     */
    public function writerSheetColumns(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ExcelDetailColumnDefinition $col): bool => ! $col->dataSheetOnly,
        ));
    }

    /**
     * Columns for SINGLE_DATA_SHEET DATA (writer_name first).
     *
     * @return list<ExcelDetailColumnDefinition>
     */
    public function dataSheetColumns(): array
    {
        return $this->all();
    }

    public function isKnown(string $code): bool
    {
        $normalized = strtolower(trim($code));
        foreach ($this->all() as $col) {
            if ($col->code === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function requiredImportCodes(): array
    {
        $codes = [];
        foreach ($this->all() as $col) {
            if ($col->requiredForImport) {
                $codes[] = $col->code;
            }
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    public function writerSheetCodes(): array
    {
        return array_map(
            static fn (ExcelDetailColumnDefinition $c): string => $c->code,
            $this->writerSheetColumns(),
        );
    }

    /**
     * @return list<string>
     */
    public function dataSheetCodes(): array
    {
        return array_map(
            static fn (ExcelDetailColumnDefinition $c): string => $c->code,
            $this->dataSheetColumns(),
        );
    }
}
