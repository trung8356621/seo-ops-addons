<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDataLayoutMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelRawTemplateGenerator;
use App\Support\RuntimeLogger;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads RAW Excel templates (structure/placeholders only — zero live report rows).
 */
final class ContentProjectExcelRawTemplateDownloadService
{
    public function __construct(
        private readonly ContentProjectExcelTemplateSettingsService $settings,
        private readonly ExcelRawTemplateGenerator $generator = new ExcelRawTemplateGenerator(),
    ) {}

    public function streamDownload(?ExcelDataLayoutMode $layoutMode = null): StreamedResponse
    {
        $mode = $layoutMode ?? $this->settings->dataLayoutMode();
        $filename = 'excel-raw-template-'.$mode->value.'.xlsx';

        return new StreamedResponse(
            function () use ($mode): void {
                $this->writeToPath($mode, 'php://output');
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'max-age=0',
            ],
        );
    }

    public function writeToPath(ExcelDataLayoutMode $mode, string $path): void
    {
        $spreadsheet = $this->generator->build($mode);

        RuntimeLogger::info('content_project_excel_raw_template_downloaded', [
            'data_layout_mode' => $mode->value,
            'user_id' => auth()->id(),
        ]);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }
}
