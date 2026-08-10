<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Data;

use Illuminate\Support\Str;

final class EventEnvelope
{
    /**
     * @param  array{type: string, id: int|string|null}  $entity
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventKey,
        public readonly string $eventId,
        public readonly string $occurredAt,
        public readonly array $entity,
        public readonly array $context,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array{type: string, id: int|string|null}  $entity
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    public static function make(
        string $eventKey,
        array $entity,
        array $context,
        array $payload = [],
        ?string $eventId = null,
        ?string $occurredAt = null,
    ): self {
        return new self(
            eventKey: $eventKey,
            eventId: $eventId ?? Str::uuid()->toString(),
            occurredAt: $occurredAt ?? now()->toIso8601String(),
            entity: $entity,
            context: $context,
            payload: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_key' => $this->eventKey,
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'entity' => $this->entity,
            'context' => $this->context,
            'payload' => $this->payload,
        ];
    }
}
