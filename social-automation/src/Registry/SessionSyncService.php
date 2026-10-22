<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Registry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Enums\AccountIdentitySource;
use Omnichannel\Addons\SocialAutomation\Enums\SessionReadyStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Sync browser sessions + account metadata from Runner.
 * Ready ≠ verified login. Never stores cookies/secrets.
 */
final class SessionSyncService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, sessions?: list<array<string, mixed>>, errors?: list<string>}
     */
    public function sync(string $agentUuid, array $payload, ?int $credentialId): array
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

        $items = $payload['sessions'] ?? null;
        if (! is_array($items)) {
            return ['ok' => false, 'errors' => ['sessions_required']];
        }

        foreach (['cookies', 'cookie', 'password', 'passwords', 'token', 'secret'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                return ['ok' => false, 'errors' => ['payload_forbidden_field:'.$forbidden]];
            }
        }
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['cookies', 'cookie', 'password', 'profile_path', 'user_data_dir'] as $forbidden) {
                if (array_key_exists($forbidden, $item)) {
                    return ['ok' => false, 'errors' => ['session_forbidden_field:'.$forbidden]];
                }
            }
        }

        try {
            $out = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($agent, $items): array {
                $result = [];
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $result[] = $this->upsertSession($agent, $item);
                }

                return $result;
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['sync_failed']];
        }

        return ['ok' => true, 'sessions' => $out];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUi(): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return [];
        }

        return SaBrowserSession::query()->with('agent')->orderByDesc('updated_at')->limit(200)->get()
            ->map(static function (SaBrowserSession $s): array {
                return [
                    'session_uuid' => $s->session_uuid,
                    'agent_uuid' => $s->agent?->agent_uuid,
                    'platform' => $s->platform,
                    'domain' => $s->domain,
                    'ready_status' => $s->ready_status,
                    'busy' => $s->locked_job_id !== null
                        && ($s->lock_expires_at === null || $s->lock_expires_at->isFuture()),
                    'last_confirmed_at' => $s->last_confirmed_at?->toIso8601String(),
                ];
            })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccountsForUi(): array
    {
        if (! SocialAutomationSchema::agentsReady()) {
            return [];
        }

        return SaAccount::query()->orderByDesc('updated_at')->limit(200)->get()
            ->map(static function (SaAccount $a): array {
                return [
                    'account_uuid' => $a->account_uuid,
                    'platform' => $a->platform,
                    'domain' => $a->domain,
                    'label' => $a->label,
                    'platform_account_id' => $a->platform_account_id,
                    'identity_source' => $a->identity_source,
                    'verified' => (bool) $a->verified,
                    'declared_username' => $a->declared_username,
                    'last_confirmed_at' => $a->last_confirmed_at?->toIso8601String(),
                ];
            })->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function upsertSession(SaAgent $agent, array $item): array
    {
        $sessionUuid = trim((string) ($item['session_uuid'] ?? ''));
        if ($sessionUuid === '') {
            $sessionUuid = (string) Str::uuid();
        }

        $ready = strtolower(trim((string) ($item['ready_status'] ?? SessionReadyStatus::Pending->value)));
        if (! in_array($ready, array_column(SessionReadyStatus::cases(), 'value'), true)) {
            $ready = SessionReadyStatus::Pending->value;
        }

        $session = SaBrowserSession::query()->firstOrNew(['session_uuid' => $sessionUuid]);
        $session->fill([
            'agent_id' => $agent->id,
            'platform' => $item['platform'] ?? $session->platform,
            'domain' => $item['domain'] ?? $session->domain,
            'ready_status' => $ready,
            'last_confirmed_at' => ! empty($item['confirmed']) ? now() : ($session->last_confirmed_at ?? now()),
            'capabilities' => is_array($item['capabilities'] ?? null) ? $item['capabilities'] : ($session->capabilities ?? []),
        ])->save();

        $accounts = is_array($item['accounts'] ?? null) ? $item['accounts'] : [];
        foreach ($accounts as $acc) {
            if (! is_array($acc)) {
                continue;
            }
            $this->bindAccount($session, $acc);
        }

        return [
            'session_uuid' => $session->session_uuid,
            'ready_status' => $session->ready_status,
            'platform' => $session->platform,
            'domain' => $session->domain,
            // Explicit: ready is operator-confirmed readiness, not verified auth.
            'login_verified' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $acc
     */
    private function bindAccount(SaBrowserSession $session, array $acc): void
    {
        $platform = strtolower(trim((string) ($acc['platform'] ?? $session->platform ?? '')));
        $domain = trim((string) ($acc['domain'] ?? $session->domain ?? $platform));
        if ($platform === '' || $domain === '') {
            return;
        }

        $platformAccountId = trim((string) ($acc['platform_account_id'] ?? ''));
        $verified = (bool) ($acc['verified'] ?? false);
        // Declared email/username never force verified=true.
        if ($verified && $platformAccountId === '') {
            $verified = false;
        }

        $source = strtolower(trim((string) ($acc['identity_source'] ?? AccountIdentitySource::Declared->value)));
        if (! in_array($source, array_column(AccountIdentitySource::cases(), 'value'), true)) {
            $source = AccountIdentitySource::Declared->value;
        }

        $query = SaAccount::query()->where('platform', $platform);
        if ($platformAccountId !== '') {
            $query->where('platform_account_id', $platformAccountId);
        } else {
            $query->whereNull('platform_account_id')
                ->where('declared_username', trim((string) ($acc['declared_username'] ?? '')));
        }
        $account = $query->first();
        if (! $account instanceof SaAccount) {
            $account = SaAccount::query()->create([
                'account_uuid' => (string) Str::uuid(),
                'platform' => $platform,
                'domain' => $domain,
                'label' => $acc['label'] ?? null,
                'declared_username' => $acc['declared_username'] ?? null,
                'declared_email' => $acc['declared_email'] ?? null,
                'platform_account_id' => $platformAccountId !== '' ? $platformAccountId : null,
                'identity_source' => $source,
                'verified' => $verified,
                'last_confirmed_at' => now(),
            ]);
        } else {
            $account->fill([
                'label' => $acc['label'] ?? $account->label,
                'declared_username' => $acc['declared_username'] ?? $account->declared_username,
                'declared_email' => $acc['declared_email'] ?? $account->declared_email,
                'identity_source' => $source,
                'verified' => $verified,
                'last_confirmed_at' => now(),
            ])->save();
        }

        $isActive = (bool) ($acc['is_active'] ?? true);
        if ($isActive) {
            SaSessionAccount::query()
                ->where('browser_session_id', $session->id)
                ->where('domain', $domain)
                ->update(['is_active' => false]);
        }

        SaSessionAccount::query()->updateOrCreate(
            [
                'browser_session_id' => $session->id,
                'account_id' => $account->id,
                'domain' => $domain,
            ],
            [
                'is_active' => $isActive,
                'bound_at' => now(),
            ]
        );
    }
}
