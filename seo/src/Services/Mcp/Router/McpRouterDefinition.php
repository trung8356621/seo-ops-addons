<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Router;

/**
 * AI-discoverable context area grouping one or more Context slices as parts.
 */
final class McpRouterDefinition
{
    /**
     * @param  list<McpPartDefinition>  $parts
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly string $whenToUse,
        public readonly string $scope,
        public readonly array $parts,
    ) {
        $seen = [];
        foreach ($parts as $part) {
            if (! $part instanceof McpPartDefinition) {
                throw new \InvalidArgumentException('Router parts must be McpPartDefinition instances.');
            }
            if (isset($seen[$part->key])) {
                throw new \InvalidArgumentException(
                    'Duplicate MCP part key under router '.$this->key.': '.$part->key
                );
            }
            $seen[$part->key] = true;
        }
    }

    public function hasPart(string $partKey): bool
    {
        return $this->part($partKey) !== null;
    }

    public function part(string $partKey): ?McpPartDefinition
    {
        foreach ($this->parts as $part) {
            if ($part->key === $partKey) {
                return $part;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function partKeys(): array
    {
        return array_map(static fn (McpPartDefinition $p): string => $p->key, $this->parts);
    }
}
