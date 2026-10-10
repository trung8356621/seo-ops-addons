<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Integration;

use InvalidArgumentException;
use RuntimeException;

final class AgentOperationHandlerRegistry
{
    /** @var array<string, callable> */
    private array $handlers = [];

    public function register(string $capability, callable $handler): void
    {
        if ($capability === '' || isset($this->handlers[$capability])) {
            throw new InvalidArgumentException("Duplicate or empty Agent handler [{$capability}].");
        }
        $this->handlers[$capability] = $handler;
    }

    public function canDispatch(string $capability): bool
    {
        return isset($this->handlers[$capability]);
    }

    public function dispatch(string $capability, mixed ...$arguments): mixed
    {
        if (! isset($this->handlers[$capability])) {
            throw new RuntimeException("No authorized Agent handler registered for [{$capability}].");
        }
        return ($this->handlers[$capability])(...$arguments);
    }
}
