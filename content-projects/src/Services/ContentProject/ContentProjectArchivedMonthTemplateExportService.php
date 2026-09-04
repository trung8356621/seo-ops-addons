<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ArchivedMonthExcelStatsBuilder;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ArchivedMonthExcelTemplateVariableFactory;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDataLayoutMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelTemplateManagedSheetWriter;
use App\Support\RuntimeLogger;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Production export into an uploaded RAW template (placeholders → live data; clone _WRITER_TEMPLATE).
 */
final class ContentProjectArchivedMonthTemplateExportService
{
    public function __construct(
        private readonly ContentProjectExcelTemplateSettingsService $settings,
        private readonly ContentProjectArchivedMonthlyWorkloadService $workload,
        private readonly ArchivedMonthExcelTemplateVariableFactory $variableFactory = new ArchivedMonthExcelTemplateVariableFactory(),
        private readonly ExcelTemplateManagedSheetWriter $managedWriter = new ExcelTemplateManagedSheetWriter(),
    ) {}

    public function canExportWithTemplate(): bool
    {
        return $this->settings->absoluteTemplatePath() !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function writeWorkbook(
        array $payload,
        string $path,
        ?ExcelDataLayoutMode $layoutMode = null,
    ): void {
        $templatePath = $this->settings->absoluteTemplatePath();
        if ($templatePath === null || ! is_file($templatePath)) {
            throw new \RuntimeException('Excel template file is missing.');
        }

        $mode = $layoutMode ?? $this->settings->dataLayoutMode();
        $spreadsheet = IOFactory::load($templatePath);

        $stats = (new ArchivedMonthExcelStatsBuilder($this->workload))->build($payload, true);
        $scalars = $this->variableFactory->buildScalarRegistry();
        $tables = $this->variableFactory->buildTableRegistry();

        $this->managedWriter->fillProductionWorkbook(
            $spreadsheet,
            $payload,
            $mode,
            $stats,
            $scalars,
            $tables,
        );

        RuntimeLogger::info('content_project_archived_month_template_exported', [
            'month' => $payload['month'] ?? '',
            'data_layout_mode' => $mode->value,
            'total_articles' => (int) ($payload['total_articles'] ?? 0),
            'writer_sheets' => count($payload['writer_sheets'] ?? []),
            'user_id' => auth()->id(),
        ]);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }
}
