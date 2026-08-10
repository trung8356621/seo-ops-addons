<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentObservabilityEventBus;

/**
 * Emits allowlisted pack.* events via Phase 6 bus.
 */
final class AgentPackEventEmitter
{
    public function __construct(
        private readonly ?AgentObservabilityEventBus $bus = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function emit(string $type, array $attributes = [], ?string $traceId = null): void
    {
        if (! in_array($type, AgentPackConstants::PACK_EVENT_TYPES, true)) {
            return;
        }
        $this->bus?->dispatch([
            'event_type' => $type,
            'trace_id' => $traceId ?? ('apack_'.bin2hex(random_bytes(8))),
            'attributes' => $attributes,
            'severity' => str_contains($type, 'failed') || str_contains($type, 'rejected') ? 'warning' : 'info',
        ]);
    }
}
