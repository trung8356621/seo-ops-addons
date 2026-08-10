<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

use Illuminate\Support\Str;

/**
 * Versioned compact extension event envelope — no Eloquent / prompt / credentials.
 *
 * @phpstan-type Envelope array{
 *   event_name: string,
 *   event_version: string,
 *   event_ref: string,
 *   occurred_at: string,
 *   tenant_ref: ?string,
 *   site_ref: ?string,
 *   project_ref: ?string,
 *   item_ref: ?string,
 *   actor_type: string,
 *   payload: array<string, mixed>
 * }
 */
final class ExtensionEventEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     * @return Envelope
     */
    public static function make(
        string $eventName,
        array $payload = [],
        ?string $tenantRef = null,
        ?string $siteRef = null,
        ?string $projectRef = null,
        ?string $itemRef = null,
        string $actorType = 'system',
    ): array {
        $version = 'v1';
        if (preg_match('/\.(v\d+)$/', $eventName, $m) === 1) {
            $version = $m[1];
        }

        return [
            'event_name' => $eventName,
            'event_version' => $version,
            'event_ref' => 'eev_'.Str::lower((string) Str::ulid()),
            'occurred_at' => now()->toIso8601String(),
            'tenant_ref' => $tenantRef,
            'site_ref' => $siteRef,
            'project_ref' => $projectRef,
            'item_ref' => $itemRef,
            'actor_type' => $actorType,
            'payload' => $payload,
        ];
    }
}
