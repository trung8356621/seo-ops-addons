<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

use App\Support\RuntimeLogger;
use Throwable;

final class ExtensionEventBus
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function subscribe(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Each subscriber runs in its own try/catch — one failing listener
     * never blocks or breaks the others.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $index => $listener) {
            try {
                $listener($payload);
            } catch (Throwable $e) {
                // Không log payload/message — có thể chứa secret. Chỉ log tên event + loại exception.
                RuntimeLogger::warning('extension.listener_failed', [
                    'event' => $event,
                    'listener_index' => $index,
                    'exception' => $e::class,
                ]);
            }
        }
    }
}
