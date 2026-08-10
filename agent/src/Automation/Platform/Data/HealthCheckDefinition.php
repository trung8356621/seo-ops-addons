<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform\Data;

final class HealthCheckDefinition
{
    /**
     * @param  callable(): array{status: string, message?: string, meta?: array<string, mixed>}  $checker
     */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly mixed $checker,
        public readonly string $description = '',
    ) {}
}
