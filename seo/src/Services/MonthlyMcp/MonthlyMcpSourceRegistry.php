<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Omnichannel\Addons\Seo\Services\MonthlyMcp\Contracts\MonthlyMcpSource;
use RuntimeException;

final class MonthlyMcpSourceRegistry
{
    /** @var array<string, MonthlyMcpSource> */
    private array $sources = [];

    /**
     * @param  iterable<MonthlyMcpSource>  $sources
     */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->key()] = $source;
        }
    }

    /**
     * @return list<MonthlyMcpSource>
     */
    public function all(): array
    {
        return array_values($this->sources);
    }

    public function get(string $key): MonthlyMcpSource
    {
        $source = $this->sources[$key] ?? null;
        if (! $source instanceof MonthlyMcpSource) {
            throw new RuntimeException('Unknown MCP monthly source: '.$key);
        }

        return $source;
    }
}
