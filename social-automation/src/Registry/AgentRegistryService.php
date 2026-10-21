<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Registry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Enums\AgentAvailability;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

final class AgentRegistryService
{
    public function __construct(
        private readonly OnlineStateCalculator $online = new OnlineStateCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, agent?: array<string, mixed>, errors?: list<string>}
     */
    public function register(array $payload, ?int $credentialId): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        if ($deviceId === '') {
            return ['ok' => false, 'errors' => ['device_id_required']];
        }

        try {
            $agent = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($payload, $deviceId, $credentialId): SaAgent {
                $existing = SaAgent::query()->where('device_id', $deviceId)->first();
                if ($existing instanceof SaAgent) {
                    if ($credentialId !== null && $existing->credential_id !== null
                        && (int) $existing->credential_id !== $credentialId) {
                        throw new \RuntimeException('credential_conflict');
                    }
                    $existing->fill([
                        'display_name' => $payload['display_name'] ?? $existing->display_name,
                        'app_version' => $payload['app_version'] ?? $existing->app_version,
                        'runtime_version' => $payload['runtime_version'] ?? $existing->runtime_version,
                        'capabilities' => is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : $existing->capabilities,
                        'max_concurrency' => max(1, (int) ($payload['max_concurrency'] ?? $existing->max_concurrency ?? 1)),
                        'credential_id' => $credentialId ?? $existing->credential_id,
                        'last_heartbeat_at' => now(),
                    ])->save();

                    return $existing->fresh() ?? $existing;
                }

                return SaAgent::query()->create([
                    'agent_uuid' => (string) Str::uuid(),
                    'device_id' => $deviceId,
                    'display_name' => $payload['display_name'] ?? null,
                    'app_version' => $payload['app_version'] ?? null,
                    'runtime_version' => $payload['runtime_version'] ?? null,
                    'capabilities' => is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [],
                    'availability' => AgentAvailability::Available->value,
                    'max_concurrency' => max(1, (int) ($payload['max_concurrency'] ?? 1)),
                    'active_jobs' => 0,
                    'last_heartbeat_at' => now(),
                    'credential_id' => $credentialId,
                ]);
            });
        } catch (Throwable $e) {
            $code = $e->getMessage() === 'credential_conflict' ? 'credential_conflict' : 'register_failed';

            return ['ok' => false, 'errors' => [$code]];
        }

        return ['ok' => true, 'agent' => $this->toArray($agent)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, agent?: array<string, mixed>, errors?: list<string>}
     */
    public function heartbeat(string $agentUuid, array $payload, ?int $credentialId): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return ['ok' => false, 'errors' => ['agent_not_found']];
        }
        if ($credentialId !== null && $agent->credential_id !== null
            && (int) $agent->credential_id !== $credentialId) {
            return ['ok' => false, 'errors' => ['credential_mismatch']];
        }

        $availability = trim((string) ($payload['availability'] ?? $agent->availability));
        if (! in_array($availability, array_column(AgentAvailability::cases(), 'value'), true)) {
            $availability = $agent->availability;
        }

        $agent->fill([
            'last_heartbeat_at' => now(),
            'availability' => $availability,
            'app_version' => $payload['app_version'] ?? $agent->app_version,
            'runtime_version' => $payload['runtime_version'] ?? $agent->runtime_version,
            'capabilities' => is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : $agent->capabilities,
            'last_error_code' => $payload['last_error_code'] ?? null,
            'last_error_message' => isset($payload['last_error_message'])
                ? substr((string) $payload['last_error_message'], 0, 2000)
                : $agent->last_error_message,
            'active_jobs' => max(0, (int) ($payload['active_jobs'] ?? $agent->active_jobs)),
        ])->save();

        return ['ok' => true, 'agent' => $this->toArray($agent->fresh() ?? $agent)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUi(): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return [];
        }

        return SaAgent::query()->orderByDesc('last_heartbeat_at')->limit(200)->get()
            ->map(fn (SaAgent $a): array => $this->toArray($a))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(SaAgent $agent): array
    {
        $hb = $agent->last_heartbeat_at;

        return [
            'agent_uuid' => $agent->agent_uuid,
            'device_id' => $agent->device_id,
            'display_name' => $agent->display_name,
            'app_version' => $agent->app_version,
            'runtime_version' => $agent->runtime_version,
            'capabilities' => $agent->capabilities ?? [],
            'availability' => $agent->availability,
            'max_concurrency' => (int) $agent->max_concurrency,
            'active_jobs' => (int) $agent->active_jobs,
            'online_state' => $this->online->state($hb),
            'last_heartbeat_at' => $hb?->toIso8601String(),
            'last_error_code' => $agent->last_error_code,
        ];
    }
}
