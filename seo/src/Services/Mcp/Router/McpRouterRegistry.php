<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Router;

use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Context\Registry\ContextRegistry;
use Omnichannel\Addons\Seo\Services\Mcp\Manifest\McpManifestBuilder;

/**
 * AI-facing discovery/grouping registry over ContextRegistry slices.
 * Not an AI planner — validates and describes only.
 */
final class McpRouterRegistry
{
    /** @var array<string, McpRouterDefinition> */
    private array $routers = [];

    /**
     * @param  iterable<McpRouterDefinition>  $routers
     */
    public function __construct(
        iterable $routers,
        private readonly ContextRegistry $contextRegistry,
        private readonly McpManifestBuilder $manifestBuilder = new McpManifestBuilder,
    ) {
        foreach ($routers as $router) {
            if (! $router instanceof McpRouterDefinition) {
                throw new InvalidArgumentException('Expected McpRouterDefinition.');
            }
            if (isset($this->routers[$router->key])) {
                throw new InvalidArgumentException('Duplicate MCP router key: '.$router->key);
            }
            foreach ($router->parts as $part) {
                if (! $this->contextRegistry->has($part->contextKey)) {
                    throw new InvalidArgumentException(
                        'MCP part '.$router->key.'.'.$part->key
                        .' maps to unregistered Context key: '.$part->contextKey
                    );
                }
            }
            $this->routers[$router->key] = $router;
        }
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->routers);
    }

    public function has(string $key): bool
    {
        return isset($this->routers[$key]);
    }

    public function router(string $key): McpRouterDefinition
    {
        if (! isset($this->routers[$key])) {
            throw new InvalidArgumentException('Unknown MCP router: '.$key);
        }

        return $this->routers[$key];
    }

    /**
     * @return list<McpRouterDefinition>
     */
    public function routers(): array
    {
        return array_values($this->routers);
    }

    public function part(string $routerKey, string $partKey): McpPartDefinition
    {
        $router = $this->router($routerKey);
        $part = $router->part($partKey);
        if (! $part instanceof McpPartDefinition) {
            throw new InvalidArgumentException(
                'Unknown MCP part for router '.$routerKey.': '.$partKey
            );
        }

        return $part;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->manifestBuilder->build($this);
    }

    public function contextRegistry(): ContextRegistry
    {
        return $this->contextRegistry;
    }
}
