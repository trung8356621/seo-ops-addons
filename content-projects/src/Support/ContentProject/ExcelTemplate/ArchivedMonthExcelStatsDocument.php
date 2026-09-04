<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * Rectangular STATS workbook blocks — machine-readable, stable ids.
 *
 * @phpstan-type Block array{id: string, title: string, rows: list<list<scalar|null>>}
 */
final class ArchivedMonthExcelStatsDocument
{
    public const SHEET_NAME = 'STATS';

    public const BLOCK_SUMMARY = 'SUMMARY';

    public const BLOCK_BY_WRITER = 'ARTICLES_BY_WRITER';

    public const BLOCK_BY_DOMAIN = 'ARTICLES_BY_DOMAIN';

    public const BLOCK_BY_TYPE = 'ARTICLES_BY_TYPE';

    public const BLOCK_BY_STATUS = 'ARTICLES_BY_STATUS';

    public const BLOCK_BY_MONTH = 'ARTICLES_BY_MONTH';

    public const BLOCK_BY_WEEK = 'ARTICLES_BY_WEEK';

    public const BLOCK_FIELD_DICTIONARY = 'FIELD_DICTIONARY';

    /**
     * @param  list<Block>  $blocks
     */
    public function __construct(
        public readonly array $blocks,
    ) {}

    /**
     * Flat rows ready to write (block title row + table + blank separator).
     *
     * @return list<list<scalar|null>>
     */
    public function toSheetRows(): array
    {
        $out = [];
        foreach ($this->blocks as $block) {
            $out[] = ['['.$block['id'].']'];
            if ($block['title'] !== '') {
                $out[] = [$block['title']];
            }
            foreach ($block['rows'] as $row) {
                $out[] = $row;
            }
            $out[] = [''];
        }

        return $out;
    }

    /**
     * @return Block|null
     */
    public function block(string $id): ?array
    {
        foreach ($this->blocks as $block) {
            if ($block['id'] === $id) {
                return $block;
            }
        }

        return null;
    }
}
