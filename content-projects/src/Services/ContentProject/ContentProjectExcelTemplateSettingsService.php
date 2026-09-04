<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDataLayoutMode;
use App\Models\WpOption;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Persists archived-month Excel template file + DATA_LAYOUT_MODE.
 */
final class ContentProjectExcelTemplateSettingsService
{
    public const OPTION_KEY = 'content_project_excel_template_settings';

    public const KEY_DATA_LAYOUT_MODE = 'data_layout_mode';

    public const KEY_TEMPLATE_PATH = 'template_path';

    public const KEY_BLOCK_EXTENTS = 'block_extents';

    public const STORAGE_DIR = 'content-projects/excel-templates';

    public const TEMPLATE_FILENAME = 'archived-month-template.xlsx';

    /**
     * @return array{data_layout_mode: string, template_path: string|null, has_template: bool}
     */
    public function getSettings(): array
    {
        $data = WpOption::get(self::OPTION_KEY, []);
        if (! is_array($data)) {
            $data = [];
        }

        $mode = ExcelDataLayoutMode::tryFromMixed($data[self::KEY_DATA_LAYOUT_MODE] ?? null)
            ?? ExcelDataLayoutMode::default();
        $path = isset($data[self::KEY_TEMPLATE_PATH]) ? trim((string) $data[self::KEY_TEMPLATE_PATH]) : '';
        if ($path === '') {
            $path = null;
        }

        $hasTemplate = $path !== null && Storage::disk('local')->exists($path);

        return [
            self::KEY_DATA_LAYOUT_MODE => $mode->value,
            self::KEY_TEMPLATE_PATH => $hasTemplate ? $path : null,
            'has_template' => $hasTemplate,
        ];
    }

    /**
     * @return array<string, string>  key = "{sheet}|{tableKey}" → A1:B10
     */
    public function blockExtents(): array
    {
        $data = $this->raw();
        $extents = $data[self::KEY_BLOCK_EXTENTS] ?? [];

        return is_array($extents) ? array_map('strval', $extents) : [];
    }

    public function rememberBlockExtent(string $sheetTitle, string $tableKey, string $a1Range): void
    {
        $current = $this->raw();
        $extents = is_array($current[self::KEY_BLOCK_EXTENTS] ?? null)
            ? $current[self::KEY_BLOCK_EXTENTS]
            : [];
        $extents[$this->extentKey($sheetTitle, $tableKey)] = $a1Range;
        $current[self::KEY_BLOCK_EXTENTS] = $extents;
        WpOption::set(self::OPTION_KEY, $current);
    }

    public function forgetBlockExtent(string $sheetTitle, string $tableKey): void
    {
        $current = $this->raw();
        $extents = is_array($current[self::KEY_BLOCK_EXTENTS] ?? null)
            ? $current[self::KEY_BLOCK_EXTENTS]
            : [];
        unset($extents[$this->extentKey($sheetTitle, $tableKey)]);
        $current[self::KEY_BLOCK_EXTENTS] = $extents;
        WpOption::set(self::OPTION_KEY, $current);
    }

    public function findBlockExtent(string $sheetTitle, string $tableKey): ?string
    {
        $extents = $this->blockExtents();
        $key = $this->extentKey($sheetTitle, $tableKey);
        $range = isset($extents[$key]) ? trim((string) $extents[$key]) : '';

        return $range !== '' ? $range : null;
    }

    private function extentKey(string $sheetTitle, string $tableKey): string
    {
        return $sheetTitle.'|'.$tableKey;
    }

    public function dataLayoutMode(): ExcelDataLayoutMode
    {
        return ExcelDataLayoutMode::tryFromMixed($this->getSettings()[self::KEY_DATA_LAYOUT_MODE])
            ?? ExcelDataLayoutMode::default();
    }

    public function saveDataLayoutMode(ExcelDataLayoutMode|string $mode): void
    {
        $resolved = ExcelDataLayoutMode::tryFromMixed($mode) ?? ExcelDataLayoutMode::default();
        $current = $this->raw();
        $current[self::KEY_DATA_LAYOUT_MODE] = $resolved->value;
        WpOption::set(self::OPTION_KEY, $current);
    }

    public function absoluteTemplatePath(): ?string
    {
        $settings = $this->getSettings();
        if (! $settings['has_template'] || $settings[self::KEY_TEMPLATE_PATH] === null) {
            return null;
        }

        return Storage::disk('local')->path($settings[self::KEY_TEMPLATE_PATH]);
    }

    public function storeTemplate(UploadedFile $file): string
    {
        $dir = self::STORAGE_DIR;
        Storage::disk('local')->makeDirectory($dir);
        $relative = $dir.'/'.self::TEMPLATE_FILENAME;
        Storage::disk('local')->put($relative, (string) file_get_contents($file->getRealPath()));

        $current = $this->raw();
        $current[self::KEY_TEMPLATE_PATH] = $relative;
        WpOption::set(self::OPTION_KEY, $current);

        return $relative;
    }

    public function deleteTemplate(): void
    {
        $current = $this->raw();
        $path = isset($current[self::KEY_TEMPLATE_PATH]) ? (string) $current[self::KEY_TEMPLATE_PATH] : '';
        if ($path !== '' && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
        unset($current[self::KEY_TEMPLATE_PATH]);
        WpOption::set(self::OPTION_KEY, $current);
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        $data = WpOption::get(self::OPTION_KEY, []);

        return is_array($data) ? $data : [];
    }
}
