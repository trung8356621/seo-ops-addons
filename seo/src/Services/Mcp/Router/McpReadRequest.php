<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Router;

use InvalidArgumentException;

/**
 * Selective multi-part read request for one router.
 *
 * Shape:
 * {
 *   "router": "keywords",
 *   "parts": {
 *     "landscape": { "view": "summary", "parameters": { "limit": 5 } },
 *     "relationship": { "view": "standard", "parameters": { "keyword_ref": "keyword:1" } }
 *   }
 * }
 */
final class McpReadRequest
{
    /**
     * @param  array<string, McpPartReadSpec>  $parts
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $router,
        public readonly array $parts,
    ) {
        if ($this->siteId <= 0) {
            throw new InvalidArgumentException('siteId must be a positive integer.');
        }
        if (trim($this->router) === '') {
            throw new InvalidArgumentException('router is required.');
        }
        if ($this->parts === []) {
            throw new InvalidArgumentException('At least one part must be requested.');
        }
        foreach ($this->parts as $key => $spec) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('Part keys must be non-empty strings.');
            }
            if (! $spec instanceof McpPartReadSpec) {
                throw new InvalidArgumentException('Each part value must be an McpPartReadSpec.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(int $siteId, array $payload): self
    {
        $router = $payload['router'] ?? null;
        if (! is_string($router) || trim($router) === '') {
            throw new InvalidArgumentException('router is required.');
        }

        $rawParts = $payload['parts'] ?? null;
        if (! is_array($rawParts) || $rawParts === []) {
            throw new InvalidArgumentException('parts must be a non-empty object keyed by part name.');
        }

        /** @var array<string, McpPartReadSpec> $parts */
        $parts = [];
        foreach ($rawParts as $partKey => $partPayload) {
            if (! is_string($partKey) || $partKey === '') {
                throw new InvalidArgumentException('Part keys must be non-empty strings.');
            }
            if (is_array($partPayload)) {
                $parts[$partKey] = McpPartReadSpec::fromArray($partPayload);
            } elseif ($partPayload === true || $partPayload === null) {
                $parts[$partKey] = new McpPartReadSpec;
            } else {
                throw new InvalidArgumentException('Invalid part payload for: '.$partKey);
            }
        }

        return new self($siteId, trim($router), $parts);
    }

    /**
     * @return list<string>
     */
    public function partKeys(): array
    {
        return array_keys($this->parts);
    }
}
