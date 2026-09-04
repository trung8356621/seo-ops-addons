<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * UI dictionary for all Excel template variables (scalar vs table).
 */
final class ExcelTemplateVariableDictionary
{
    public function __construct(
        private readonly ArchivedMonthExcelTemplateVariableFactory $factory = new ArchivedMonthExcelTemplateVariableFactory(),
    ) {}

    /**
     * @return array{
     *     scalars: list<array{key: string, placeholder: string, label: string, description: string}>,
     *     tables: list<array{key: string, placeholder: string, label: string, description: string, columns: list<string>, expands_note: string}>
     * }
     */
    public function toArray(): array
    {
        $scalars = [];
        foreach ($this->factory->buildScalarRegistry()->all() as $def) {
            $scalars[] = [
                'key' => $def->key,
                'placeholder' => $def->placeholder(),
                'label' => $def->label,
                'description' => $def->description,
            ];
        }

        $expandsNote = (string) __('seo-content-ai::filament.projects.excel_tpl_table_expands_note');
        $tables = [];
        foreach ($this->factory->buildTableRegistry()->all() as $def) {
            $tables[] = [
                'key' => $def->key,
                'placeholder' => $def->placeholder(),
                'label' => $def->label,
                'description' => $def->description,
                'columns' => $def->columns,
                'expands_note' => $expandsNote,
            ];
        }

        return [
            'scalars' => $scalars,
            'tables' => $tables,
        ];
    }
}
