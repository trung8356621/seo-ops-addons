<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * One-cell template placeholder (e.g. {{month}}).
 */
final class ExcelScalarVariableDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
    ) {}

    public function placeholder(): string
    {
        return '{{'.$this->key.'}}';
    }
}
