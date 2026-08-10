<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use InvalidArgumentException;

/**
 * Agent execution context — readonly DTO từ HTTP/MCP request.
 */
final class AgentExecutionContext
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $actorRef,
        public readonly string $actorType,
        public readonly string $tenantRef,
        public readonly string $siteRef,
        public readonly string $requestRef,
        public readonly ?string $sessionRef = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $confirmationToken = null,
        public readonly bool $dryRun = false,
        public readonly string $locale = 'vi',
        public readonly string $timezone = 'Asia/Ho_Chi_Minh',
        public readonly ?int $resolvedSiteId = null,
        public readonly ?int $resolvedActorUserId = null,
        public readonly array $scopes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tenantRef = trim((string) ($data['tenant_ref'] ?? ''));
        $siteRef = trim((string) ($data['site_ref'] ?? ''));
        $actorRef = trim((string) ($data['actor_ref'] ?? ''));
        $requestRef = trim((string) ($data['request_ref'] ?? ''));

        if ($tenantRef === '' || $siteRef === '' || $actorRef === '' || $requestRef === '') {
            throw new InvalidArgumentException('Missing required agent context fields.');
        }

        $scopes = $data['scopes'] ?? [];
        if (! is_array($scopes)) {
            $scopes = [];
        }

        return new self(
            actorRef: $actorRef,
            actorType: (string) ($data['actor_type'] ?? 'agent'),
            tenantRef: $tenantRef,
            siteRef: $siteRef,
            requestRef: $requestRef,
            sessionRef: isset($data['session_ref']) ? (string) $data['session_ref'] : null,
            idempotencyKey: isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null,
            confirmationToken: isset($data['confirmation_token']) ? (string) $data['confirmation_token'] : null,
            dryRun: (bool) ($data['dry_run'] ?? false),
            locale: (string) ($data['locale'] ?? 'vi'),
            timezone: (string) ($data['timezone'] ?? 'Asia/Ho_Chi_Minh'),
            resolvedSiteId: isset($data['resolved_site_id']) ? (int) $data['resolved_site_id'] : null,
            resolvedActorUserId: isset($data['resolved_actor_user_id']) ? (int) $data['resolved_actor_user_id'] : null,
            scopes: array_values(array_filter(array_map('strval', $scopes))),
        );
    }

    public function withResolved(int $siteId, ?int $actorUserId = null): self
    {
        return new self(
            actorRef: $this->actorRef,
            actorType: $this->actorType,
            tenantRef: $this->tenantRef,
            siteRef: $this->siteRef,
            requestRef: $this->requestRef,
            sessionRef: $this->sessionRef,
            idempotencyKey: $this->idempotencyKey,
            confirmationToken: $this->confirmationToken,
            dryRun: $this->dryRun,
            locale: $this->locale,
            timezone: $this->timezone,
            resolvedSiteId: $siteId,
            resolvedActorUserId: $actorUserId ?? $this->resolvedActorUserId,
            scopes: $this->scopes,
        );
    }

    public function toActorContext(): ActorContext
    {
        $idem = $this->idempotencyKey !== null && $this->idempotencyKey !== ''
            ? 'agent:'.$this->idempotencyKey
            : null;

        return new ActorContext(
            actorType: 'agent',
            actorId: $this->resolvedActorUserId,
            siteId: $this->resolvedSiteId,
            idempotencyKey: $idem,
            correlationId: $this->requestRef,
            dryRun: $this->dryRun,
            confirmationToken: $this->confirmationToken,
        );
    }
}
