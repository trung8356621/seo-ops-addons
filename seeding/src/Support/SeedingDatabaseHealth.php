<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

use Omnichannel\Addons\Seeding\Services\SeedingDatabaseConnectionService;

/**
 * Operational readiness for the Seeding DB plane — no business tables required.
 * Delegates to SeedingDatabaseConnectionService (saved row → env → unavailable).
 */
final class SeedingDatabaseHealth
{
    public function __construct(
        private readonly SeedingDatabaseConnectionService $connections,
    ) {}

    /**
     * @return array{
     *     connection: string,
     *     database: string,
     *     configured: bool,
     *     reachable: bool,
     *     error: ?string,
     *     source?: string
     * }
     */
    public function check(): array
    {
        $health = $this->connections->healthCheck();

        return [
            'connection' => (string) ($health['connection'] ?? SeedingServiceConfig::CONNECTION),
            'database' => (string) ($health['database'] ?? ''),
            'configured' => (bool) ($health['configured'] ?? false),
            'reachable' => (bool) ($health['reachable'] ?? false),
            'error' => $health['error'] ?? null,
            'source' => (string) ($health['source'] ?? 'env'),
        ];
    }
}
