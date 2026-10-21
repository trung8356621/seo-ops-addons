<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use Omnichannel\Addons\SocialAutomation\Services\SocialAutomationDatabaseConnectionService;

/**
 * Operational readiness for the Social Automation DB plane.
 */
final class SocialAutomationDatabaseHealth
{
    public function __construct(
        private readonly SocialAutomationDatabaseConnectionService $connections,
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
            'connection' => (string) ($health['connection'] ?? SocialAutomationServiceConfig::CONNECTION),
            'database' => (string) ($health['database'] ?? ''),
            'configured' => (bool) ($health['configured'] ?? false),
            'reachable' => (bool) ($health['reachable'] ?? false),
            'error' => $health['error'] ?? null,
            'source' => (string) ($health['source'] ?? 'env'),
        ];
    }
}
