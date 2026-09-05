<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use App\Models\SeedingDatabaseConnection;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceIdentity;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use RuntimeException;
use Throwable;

/**
 * Seeding DB plane adapter.
 *
 * Precedence:
 *   ServiceDatabaseConnection (canonical)
 *     → legacy seeding_database_connections
 *     → SEEDING_DB_* env
 */
final class SeedingDatabaseConnectionService
{
    private static ?string $bootstrappedFingerprint = null;

    public function __construct(
        private readonly ServiceDatabaseConnectionResolver $resolver,
    ) {}

    public function connectionName(): string
    {
        return SeedingServiceConfig::CONNECTION;
    }

    public function activeConnection(): ?SeedingDatabaseConnection
    {
        if (! Schema::hasTable('seeding_database_connections')) {
            return null;
        }

        $row = SeedingDatabaseConnection::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        return $row instanceof SeedingDatabaseConnection ? $row : null;
    }

    public function bootstrap(?SeedingDatabaseConnection $connection = null, bool $forceReconnect = false): void
    {
        if ($connection === null && $this->resolver->tryBootstrap(ServiceIdentity::PUBLIC_SEEDING, $forceReconnect)) {
            return;
        }

        $connection ??= $this->activeConnection();
        $config = $connection instanceof SeedingDatabaseConnection
            ? $this->resolveConnectionArrayFromModel($connection)
            : $this->envFallbackConfig();

        $name = $this->connectionName();
        $fingerprint = md5((string) json_encode($config));

        Config::set('database.connections.'.$name, $config);

        if (! $forceReconnect && self::$bootstrappedFingerprint === $fingerprint) {
            return;
        }

        DB::purge($name);
        self::$bootstrappedFingerprint = $fingerprint;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function testConnectionFromAttributes(array $attributes, ?string $plainPasswordOverride = null): void
    {
        $this->resolver->testAttributes($attributes, $plainPasswordOverride);
    }

    public function testConnectionForModel(SeedingDatabaseConnection $connection, ?string $plainPasswordOverride = null): void
    {
        $attrs = $connection->only([
            'type', 'host', 'port', 'database', 'username', 'password', 'is_active',
        ]);
        $this->resolver->testAttributes($attrs, $plainPasswordOverride);
    }

    /**
     * @return array{
     *     source: string,
     *     connection: string,
     *     database: string,
     *     configured: bool,
     *     reachable: bool,
     *     error: ?string
     * }
     */
    public function healthCheck(): array
    {
        $core = $this->resolver->health(ServiceIdentity::PUBLIC_SEEDING);
        if ($core['configured']) {
            return [
                'source' => 'service_database_connections',
                'connection' => $core['connection'],
                'database' => $core['database'],
                'configured' => true,
                'reachable' => $core['reachable'],
                'error' => $core['error'],
            ];
        }

        $name = $this->connectionName();
        $active = $this->activeConnection();
        $source = $active instanceof SeedingDatabaseConnection ? 'legacy_seeding' : 'env';

        try {
            $this->bootstrap($active, forceReconnect: true);
        } catch (Throwable) {
            return [
                'source' => $source,
                'connection' => $name,
                'database' => (string) ($active?->database ?: config('database.connections.'.$name.'.database', '')),
                'configured' => false,
                'reachable' => false,
                'error' => 'bootstrap_failed',
            ];
        }

        $database = (string) config('database.connections.'.$name.'.database', '');
        if ($database === '' || strcasecmp($database, 'omi_seo_ai') === 0) {
            return [
                'source' => $source,
                'connection' => $name,
                'database' => $database,
                'configured' => $active instanceof SeedingDatabaseConnection || $this->envLooksConfigured(),
                'reachable' => false,
                'error' => $database === '' ? 'database_empty' : 'invalid_database_name_omi_seo_ai',
            ];
        }

        $configured = $active instanceof SeedingDatabaseConnection || $this->envLooksConfigured();

        try {
            DB::connection($name)->getPdo();
            DB::connection($name)->select('select 1 as ok');

            return [
                'source' => $source,
                'connection' => $name,
                'database' => $database,
                'configured' => true,
                'reachable' => true,
                'error' => null,
            ];
        } catch (Throwable) {
            return [
                'source' => $source,
                'connection' => $name,
                'database' => $database,
                'configured' => $configured,
                'reachable' => false,
                'error' => 'unreachable',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConnectionArrayFromModel(SeedingDatabaseConnection $connection): array
    {
        $database = trim((string) ($connection->database ?? ''));
        $username = trim((string) ($connection->username ?? ''));

        if ($database === '' || $username === '') {
            throw new RuntimeException('Cấu hình Seeding DB thiếu tên database hoặc username.');
        }

        if (strcasecmp($database, 'omi_seo_ai') === 0) {
            throw new RuntimeException('Seeding không được trỏ database sang omi_seo_ai.');
        }

        $mysql = Config::get('database.connections.mysql', []);
        if (! is_array($mysql)) {
            $mysql = [];
        }

        return array_merge($mysql, [
            'driver' => 'mysql',
            'host' => filled($connection->host) ? (string) $connection->host : '127.0.0.1',
            'port' => filled($connection->port) ? (string) $connection->port : '3306',
            'database' => $database,
            'username' => $username,
            'password' => (string) ($connection->password ?? ''),
            'charset' => $mysql['charset'] ?? 'utf8mb4',
            'collation' => $mysql['collation'] ?? 'utf8mb4_unicode_ci',
            'prefix' => $mysql['prefix'] ?? '',
            'strict' => $mysql['strict'] ?? true,
            'engine' => $mysql['engine'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function envFallbackConfig(): array
    {
        $existing = Config::get('database.connections.'.$this->connectionName(), []);
        if (! is_array($existing) || $existing === []) {
            $mysql = Config::get('database.connections.mysql', []);
            $existing = is_array($mysql) ? $mysql : [];
        }

        $database = (string) ($existing['database'] ?? env('SEEDING_DB_DATABASE', 'omi_seeding'));
        if ($database === '' || strcasecmp($database, 'omi_seo_ai') === 0) {
            $database = 'omi_seeding';
        }

        return array_merge($existing, [
            'driver' => 'mysql',
            'database' => $database,
        ]);
    }

    private function envLooksConfigured(): bool
    {
        return filled(env('SEEDING_DB_URL'))
            || filled(env('SEEDING_DB_DATABASE'))
            || filled(env('SEEDING_DB_HOST'));
    }
}
