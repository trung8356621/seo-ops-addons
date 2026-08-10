<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform\Registry;

use Omnichannel\Addons\Agent\Automation\Platform\Data\HealthCheckDefinition;
use InvalidArgumentException;

final class AutomationHealthCheckRegistry
{
    /** @var array<string, HealthCheckDefinition> */
    private array $checks = [];

    public function register(HealthCheckDefinition $definition): void
    {
        if (isset($this->checks[$definition->key])) {
            throw new InvalidArgumentException("Health check [{$definition->key}] already registered.");
        }

        $this->checks[$definition->key] = $definition;
    }

    /**
     * @return array<string, HealthCheckDefinition>
     */
    public function all(): array
    {
        return $this->checks;
    }

    /**
     * @return array<string, array{status: string, message?: string, meta?: array<string, mixed>}>
     */
    public function runAll(): array
    {
        $results = [];

        foreach ($this->checks as $key => $definition) {
            try {
                $result = ($definition->checker)();
                $results[$key] = is_array($result) ? $result : ['status' => 'unknown'];
            } catch (\Throwable $e) {
                $results[$key] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
