<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

use RuntimeException;

/**
 * Maps stable system column codes to 1-based Excel column indexes.
 */
final class ExcelDetailColumnMap
{
    /**
     * @param  array<string, int>  $codeToColumn  code => 1-based column index
     */
    public function __construct(
        public readonly array $codeToColumn,
    ) {}

    public function has(string $code): bool
    {
        return isset($this->codeToColumn[$code]);
    }

    public function column(string $code): ?int
    {
        return $this->codeToColumn[$code] ?? null;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->codeToColumn);
    }
}
