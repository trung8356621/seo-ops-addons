<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Support\PaidLockReason;

/**
 * Single write authority for api_connections.paid_locked + paid_lock_reasons.
 * Runtime health must not own a parallel paid-lock authority.
 */
final class ConnectionPaidLockService
{
    public function addReason(ApiConnection $connection, PaidLockReason|string $reason): ApiConnection
    {
        $value = $this->normalizeReason($reason);

        return $this->mutate($connection, function (array $reasons) use ($value): array {
            if (! in_array($value, $reasons, true)) {
                $reasons[] = $value;
            }

            return array_values($reasons);
        });
    }

    public function removeReason(ApiConnection $connection, PaidLockReason|string $reason): ApiConnection
    {
        $value = $this->normalizeReason($reason);

        return $this->mutate($connection, function (array $reasons) use ($value): array {
            return array_values(array_filter(
                $reasons,
                static fn (string $item): bool => $item !== $value,
            ));
        });
    }

    public function reconcile(ApiConnection $connection): ApiConnection
    {
        return $this->mutate($connection, fn (array $reasons): array => $reasons);
    }

    /**
     * @return list<string>
     */
    public function reasonValues(ApiConnection $connection): array
    {
        return $this->normalizeReasons($connection->getAttribute('paid_lock_reasons'));
    }

    public function hasReason(ApiConnection $connection, PaidLockReason|string $reason): bool
    {
        $value = $this->normalizeReason($reason);

        return in_array($value, $this->reasonValues($connection), true);
    }

    public function isPaidLocked(ApiConnection $connection): bool
    {
        if (array_key_exists('paid_locked', $connection->getAttributes())) {
            return (bool) $connection->getAttribute('paid_locked');
        }

        return $this->reasonValues($connection) !== [];
    }

    private function columnsReady(ApiConnection $connection): bool
    {
        $name = (string) ($connection->getConnectionName() ?: config('database.core_connection', 'mysql'));

        try {
            return Schema::connection($name)->hasColumn('api_connections', 'paid_locked');
        } catch (\Throwable) {
            return false;
        }
    }

    private function reasonsColumnReady(ApiConnection $connection): bool
    {
        $name = (string) ($connection->getConnectionName() ?: config('database.core_connection', 'mysql'));

        try {
            return Schema::connection($name)->hasColumn('api_connections', 'paid_lock_reasons');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  callable(list<string>): list<string>  $mutator
     */
    private function mutate(ApiConnection $connection, callable $mutator): ApiConnection
    {
        $id = (int) $connection->getKey();
        if ($id <= 0) {
            return $connection;
        }

        if (! $this->columnsReady($connection)) {
            return $connection;
        }

        $connectionName = (string) ($connection->getConnectionName() ?: config('database.core_connection', 'mysql'));
        $hasReasons = $this->reasonsColumnReady($connection);

        return DB::connection($connectionName)->transaction(function () use ($id, $mutator, $connectionName, $hasReasons): ApiConnection {
            /** @var ApiConnection $row */
            $row = ApiConnection::on($connectionName)->lockForUpdate()->findOrFail($id);
            $current = $hasReasons
                ? $this->normalizeReasons($row->getAttribute('paid_lock_reasons'))
                : (($row->getAttribute('paid_locked') ?? false) ? ['legacy'] : []);
            $reasons = $mutator($current);
            if ($hasReasons) {
                $reasons = array_values(array_filter(
                    $reasons,
                    static fn (string $item): bool => $item !== 'legacy',
                ));
                $row->setAttribute('paid_lock_reasons', $reasons);
                $row->setAttribute('paid_locked', $reasons !== []);
            } else {
                $row->setAttribute('paid_locked', $reasons !== []);
            }
            $row->save();

            return $row->refresh();
        });
    }

    /**
     * @return list<string>
     */
    private function normalizeReasons(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item) || $item === '') {
                continue;
            }
            if (! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function normalizeReason(PaidLockReason|string $reason): string
    {
        return $reason instanceof PaidLockReason ? $reason->value : $reason;
    }
}
