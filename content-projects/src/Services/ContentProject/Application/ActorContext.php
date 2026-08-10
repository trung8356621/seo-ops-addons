<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application;

/**
 * Ai gọi command: user | agent | system | queue | api
 */
final class ActorContext
{
    public function __construct(
        public readonly string $actorType,
        public readonly ?int $actorId = null,
        public readonly ?int $siteId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $correlationId = null,
        public readonly bool $dryRun = false,
        public readonly ?string $confirmationToken = null,
    ) {}

    public static function user(?int $userId, ?int $siteId = null, ?string $idempotencyKey = null): self
    {
        return new self('user', $userId, $siteId, $idempotencyKey);
    }

    public static function api(?int $userId, ?int $siteId = null, ?string $idempotencyKey = null): self
    {
        return new self('api', $userId, $siteId, $idempotencyKey);
    }

    public static function system(?string $correlationId = null): self
    {
        return new self('system', null, null, null, $correlationId);
    }

    public static function queue(?string $correlationId = null): self
    {
        return new self('queue', null, null, null, $correlationId);
    }

    public static function agent(?int $actorId, ?int $siteId = null, ?string $idempotencyKey = null): self
    {
        return new self('agent', $actorId, $siteId, $idempotencyKey);
    }
}
