<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\SupportTicket;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Optional remote delivery for support tickets.
 * Local MySQL row is always persisted first by the controller.
 */
final class SupportTicketDeliveryService
{
    public function isRemoteEnabled(): bool
    {
        if (! (bool) config('services.support_ticket.enabled', false)) {
            return false;
        }

        $endpoint = trim((string) config('services.support_ticket.endpoint', ''));

        return $endpoint !== '';
    }

    /**
     * @return array{ok: bool, remote_ticket_id: string|null, error: string|null}
     */
    public function attemptDelivery(SupportTicket $ticket): array
    {
        if (! $this->isRemoteEnabled()) {
            return [
                'ok' => false,
                'remote_ticket_id' => null,
                'error' => 'remote_disabled',
            ];
        }

        $endpoint = trim((string) config('services.support_ticket.endpoint', ''));
        $timeout = max(1, (int) config('services.support_ticket.timeout', 5));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, [
                    'local_ticket_id' => $ticket->id,
                    'title' => $ticket->title,
                    'body' => $ticket->body,
                    'connection_hash' => $ticket->connection_hash,
                    'metadata' => $ticket->metadata ?? [],
                    'created_at' => $ticket->created_at?->toIso8601String(),
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'remote_ticket_id' => null,
                    'error' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
                ];
            }

            $remoteId = $response->json('ticket_id')
                ?? $response->json('id')
                ?? $response->json('remote_ticket_id');

            return [
                'ok' => true,
                'remote_ticket_id' => is_scalar($remoteId) ? (string) $remoteId : null,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'remote_ticket_id' => null,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ];
        }
    }
}
