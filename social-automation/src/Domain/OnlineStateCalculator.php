<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Server-computed online/offline from heartbeat age. Pure — no DB.
 */
final class OnlineStateCalculator
{
    public const DEFAULT_ONLINE_TTL_SECONDS = 90;

    public function __construct(
        private readonly int $onlineTtlSeconds = self::DEFAULT_ONLINE_TTL_SECONDS,
    ) {}

    public function isOnline(?\DateTimeInterface $lastHeartbeatAt, ?\DateTimeInterface $now = null): bool
    {
        if ($lastHeartbeatAt === null) {
            return false;
        }

        $nowTs = ($now ?? new \DateTimeImmutable('now'))->getTimestamp();
        $age = $nowTs - $lastHeartbeatAt->getTimestamp();

        return $age >= 0 && $age <= $this->onlineTtlSeconds;
    }

    public function state(?\DateTimeInterface $lastHeartbeatAt, ?\DateTimeInterface $now = null): string
    {
        return $this->isOnline($lastHeartbeatAt, $now) ? 'online' : 'offline';
    }
}
