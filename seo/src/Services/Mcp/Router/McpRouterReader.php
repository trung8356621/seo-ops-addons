<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Router;

use InvalidArgumentException;

/**
 * Selective multi-part reader — always delegates to ContextRegistry::format().
 */
final class McpRouterReader
{
    public const SCHEMA = 'seo.mcp.router.read.v1';

    public function __construct(
        private readonly McpRouterRegistry $routers,
    ) {}

    /**
     * @return array{
     *   schema: string,
     *   router: string,
     *   scope: array{site_ref: string},
     *   parts: array<string, array<string, mixed>>
     * }
     */
    public function read(McpReadRequest $request): array
    {
        $router = $this->routers->router($request->router);
        $context = $this->routers->contextRegistry();

        // Fail-fast: validate every part belongs to this router before any read.
        foreach ($request->partKeys() as $partKey) {
            if (! $router->hasPart($partKey)) {
                throw new InvalidArgumentException(
                    'Unknown MCP part for router '.$router->key.': '.$partKey
                );
            }
        }

        $parts = [];
        foreach ($request->parts as $partKey => $spec) {
            $part = $router->part($partKey);
            if (! $part instanceof McpPartDefinition) {
                throw new InvalidArgumentException(
                    'Unknown MCP part for router '.$router->key.': '.$partKey
                );
            }

            // ContextRegistry remains authoritative for view/param validation.
            $parts[$partKey] = $context->format(
                $request->siteId,
                $part->contextKey,
                $spec->view,
                $spec->parameters,
            );
        }

        return [
            'schema' => self::SCHEMA,
            'router' => $router->key,
            'scope' => [
                'site_ref' => 'site:'.$request->siteId,
            ],
            'parts' => $parts,
        ];
    }
}
