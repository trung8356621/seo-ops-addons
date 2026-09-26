<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Mcp\Router;

/**
 * Per-part view + parameter bag for a selective multi-part read.
 */
final class McpPartReadSpec
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public readonly ?string $view = null,
        public readonly array $parameters = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromArray(?array $payload): self
    {
        if ($payload === null) {
            return new self;
        }

        $view = $payload['view'] ?? null;
        $parameters = $payload['parameters'] ?? [];
        if (! is_array($parameters)) {
            throw new \InvalidArgumentException('Part parameters must be an object/array.');
        }

        return new self(
            view: is_string($view) || $view === null ? $view : throw new \InvalidArgumentException('Part view must be a string.'),
            parameters: $parameters,
        );
    }
}
