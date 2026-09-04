<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * Rectangular block placeholder (e.g. {{table.articles_by_domain}}).
 *
 * @phpstan-type DatasetProvider callable(array<string, mixed>): list<list<scalar|null>>
 */
final class ExcelTableVariableDefinition
{
    /**
     * @param  list<string>  $columns
     * @param  DatasetProvider  $provider  Returns header row + data rows (2D block).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $columns,
        public readonly mixed $provider,
    ) {}

    public function placeholder(): string
    {
        return '{{'.$this->key.'}}';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<list<scalar|null>>
     */
    public function dataset(array $context): array
    {
        $provider = $this->provider;
        if (! is_callable($provider)) {
            return [$this->columns];
        }

        /** @var list<list<scalar|null>> $rows */
        $rows = $provider($context);

        return $rows;
    }
}
