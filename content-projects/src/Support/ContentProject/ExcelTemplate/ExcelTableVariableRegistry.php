<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * Extensible registry of table/block Excel template variables.
 */
final class ExcelTableVariableRegistry
{
    /** @var array<string, ExcelTableVariableDefinition> */
    private array $definitions = [];

    public function register(ExcelTableVariableDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): ?ExcelTableVariableDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @return list<ExcelTableVariableDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * @return array<string, ExcelTableVariableDefinition>
     */
    public function map(): array
    {
        return $this->definitions;
    }
}
