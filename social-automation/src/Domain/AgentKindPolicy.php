<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * RPA Admin browser sessions must not count as Runner online for dispatch.
 */
final class AgentKindPolicy
{
    public const KIND_RUNNER = 'runner';

    public const KIND_ADMIN = 'admin';

    public function isRunner(string $kind): bool
    {
        return strtolower(trim($kind)) === self::KIND_RUNNER;
    }

    public function countsAsOnlineForDispatch(string $kind, bool $heartbeatOnline): bool
    {
        return $this->isRunner($kind) && $heartbeatOnline;
    }

    public function normalize(?string $kind): string
    {
        $k = strtolower(trim((string) $kind));
        if ($k === self::KIND_ADMIN) {
            return self::KIND_ADMIN;
        }

        return self::KIND_RUNNER;
    }
}
