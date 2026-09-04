<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * Extensible registry of scalar Excel template variables.
 */
final class ExcelScalarVariableRegistry
{
    /** @var array<string, ExcelScalarVariableDefinition> */
    private array $definitions = [];

    public function register(ExcelScalarVariableDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): ?ExcelScalarVariableDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @return list<ExcelScalarVariableDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * @return array<string, ExcelScalarVariableDefinition>
     */
    public function map(): array
    {
        return $this->definitions;
    }
}
