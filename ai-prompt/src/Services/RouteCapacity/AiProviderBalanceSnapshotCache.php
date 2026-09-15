<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\RouteCapacity;

use App\Models\ApiConnection;
use Carbon\Carbon;
use Omnichannel\Addons\AiPrompt\Services\Wallet\AiProviderWalletService;

/**
 * Short-TTL balance snapshots — at most one wallet refresh per connection per TTL window.
 * Never invents balances; stale/unknown stay non-trustworthy.
 */
final class AiProviderBalanceSnapshotCache
{
    public const TTL_SECONDS = 300;

    /** @var array<int, AiProviderBalanceSnapshot> */
    private static array $snapshots = [];

    /** @var array<int, true> connections refreshed via wallet API in this process window */
    private static array $refreshed = [];

    public function __construct(
        private readonly ?AiProviderWalletService $wallet = null,
    ) {}

    public static function clear(): void
    {
        self::$snapshots = [];
        self::$refreshed = [];
    }

    public function invalidate(int $connectionId): void
    {
        unset(self::$snapshots[$connectionId], self::$refreshed[$connectionId]);
    }

    public function put(AiProviderBalanceSnapshot $snapshot): void
    {
        self::$snapshots[$snapshot->connectionId] = $snapshot;
    }

    public function snapshotFor(ApiConnection $connection, bool $allowRefresh = true): AiProviderBalanceSnapshot
    {
        $connectionId = (int) $connection->id;
        if ($connectionId <= 0) {
            return AiProviderBalanceSnapshot::unknown(0, 'missing_connection_id');
        }

        $cached = self::$snapshots[$connectionId] ?? null;
        if ($cached instanceof AiProviderBalanceSnapshot && ! $this->isExpired($cached)) {
            return $cached;
        }

        $fromColumn = $this->fromConnectionColumns($connection);
        if ($fromColumn->trustworthy && ! $this->isExpired($fromColumn)) {
            $this->put($fromColumn);

            return $fromColumn;
        }

        if ($allowRefresh && ! isset(self::$refreshed[$connectionId])) {
            $refreshed = $this->refreshFromWallet($connection);
            self::$refreshed[$connectionId] = true;
            if ($refreshed !== null) {
                $this->put($refreshed);

                return $refreshed;
            }
        }

        // Stale/unknown — do not invent; wallet rule must no-op.
        $unknown = $fromColumn->trustworthy
            ? $fromColumn // expired but still expose value as non-authoritative
            : AiProviderBalanceSnapshot::unknown($connectionId, $fromColumn->source);
        if ($fromColumn->trustworthy && $this->isExpired($fromColumn)) {
            $unknown = new AiProviderBalanceSnapshot(
                connectionId: $connectionId,
                balanceUsd: $fromColumn->balanceUsd,
                currency: $fromColumn->currency,
                trustworthy: false,
                source: 'stale_connection_column',
                observedAt: $fromColumn->observedAt,
                expiresAt: $fromColumn->expiresAt,
                status: $fromColumn->status,
            );
        }
        $this->put($unknown);

        return $unknown;
    }

    private function fromConnectionColumns(ApiConnection $connection): AiProviderBalanceSnapshot
    {
        $connectionId = (int) $connection->id;
        $status = strtolower(trim((string) ($connection->balance_status ?? '')));
        $currency = strtoupper(trim((string) ($connection->currency ?? 'USD'))) ?: 'USD';
        $observedAt = null;
        if ($connection->balance_checked_at !== null) {
            try {
                $observedAt = Carbon::parse($connection->balance_checked_at);
            } catch (\Throwable) {
                $observedAt = null;
            }
        }

        $expiresAt = $observedAt?->copy()->addSeconds(self::TTL_SECONDS);
        $balanceRaw = $connection->balance;
        $hasNumeric = $balanceRaw !== null && $balanceRaw !== '' && is_numeric($balanceRaw);
        $trustworthy = $hasNumeric
            && in_array($status, ['normal', 'low_balance'], true)
            && $currency === 'USD';

        return new AiProviderBalanceSnapshot(
            connectionId: $connectionId,
            balanceUsd: $hasNumeric ? (float) $balanceRaw : null,
            currency: $currency,
            trustworthy: $trustworthy,
            source: 'connection_column',
            observedAt: $observedAt,
            expiresAt: $expiresAt,
            status: $status !== '' ? $status : null,
        );
    }

    private function refreshFromWallet(ApiConnection $connection): ?AiProviderBalanceSnapshot
    {
        $wallet = $this->wallet;
        if ($wallet === null && function_exists('app')) {
            try {
                $wallet = app(AiProviderWalletService::class);
            } catch (\Throwable) {
                return null;
            }
        }
        if (! $wallet instanceof AiProviderWalletService) {
            return null;
        }

        $provider = (string) ($connection->provider ?? '');
        if (! $wallet->supportsBalance($provider)) {
            return AiProviderBalanceSnapshot::unknown((int) $connection->id, 'wallet_unsupported');
        }

        try {
            $result = $wallet->checkConnectionBalance($connection);
            $connection->refresh();
        } catch (\Throwable) {
            return null;
        }

        if (! $result->success || $result->balance === null) {
            return AiProviderBalanceSnapshot::unknown((int) $connection->id, 'wallet_check_failed');
        }

        $currency = strtoupper(trim($result->currency ?: 'USD')) ?: 'USD';
        $now = now();

        return new AiProviderBalanceSnapshot(
            connectionId: (int) $connection->id,
            balanceUsd: $currency === 'USD' ? (float) $result->balance : null,
            currency: $currency,
            trustworthy: $currency === 'USD',
            source: 'wallet_refresh',
            observedAt: $now,
            expiresAt: $now->copy()->addSeconds(self::TTL_SECONDS),
            status: (string) ($connection->balance_status ?? 'normal'),
        );
    }

    private function isExpired(AiProviderBalanceSnapshot $snapshot): bool
    {
        if ($snapshot->expiresAt === null) {
            return false;
        }

        return Carbon::parse($snapshot->expiresAt)->isPast();
    }
}
