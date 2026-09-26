<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Manifest;

use Omnichannel\Addons\Seo\Services\Mcp\Router\McpPartDefinition;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterDefinition;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterRegistry;

/**
 * Builds the AI-readable router manifest from MCP + Context definitions.
 * Does not expose PHP class names.
 */
final class McpManifestBuilder
{
    public const SCHEMA = 'seo.mcp.router.v1';

    /**
     * @return array{
     *   schema: string,
     *   routers: list<array<string, mixed>>
     * }
     */
    public function build(McpRouterRegistry $registry): array
    {
        $routers = [];
        foreach ($registry->routers() as $router) {
            $routers[] = $this->resolveRouter($registry, $router);
        }

        return [
            'schema' => self::SCHEMA,
            'routers' => $routers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveRouter(McpRouterRegistry $registry, McpRouterDefinition $router): array
    {
        $parts = [];
        foreach ($router->parts as $part) {
            $parts[] = $this->resolvePart($registry, $part);
        }

        return [
            'key' => $router->key,
            'title' => $router->title,
            'description' => $router->description,
            'when_to_use' => $router->whenToUse,
            'scope' => $router->scope,
            'parts' => $parts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePart(McpRouterRegistry $registry, McpPartDefinition $part): array
    {
        $def = $registry->contextRegistry()->definition($part->contextKey);

        return [
            'key' => $part->key,
            'context_key' => $part->contextKey,
            'description' => $part->descriptionOverride ?? $def->description,
            'when_to_use' => $part->whenToUse,
            'views' => $def->views,
            'default_view' => $def->defaultView,
            'required_parameters' => $def->requiredParameters,
            'optional_parameters' => $def->optionalParameters,
            'period_aware' => $def->periodAware,
            'size_hint' => $part->sizeHint->value,
        ];
    }
}
