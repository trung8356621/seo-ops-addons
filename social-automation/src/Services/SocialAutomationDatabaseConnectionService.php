<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use App\Services\ServiceDatabaseConnectionResolver;
use App\Support\Database\DriverAwareConnectionConfig;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;
use Throwable;

/**
 * Social Automation DB plane adapter.
 *
 * Precedence:
 *   ServiceDatabaseConnection (canonical, slug=social-automation)
 *     → SOCIAL_AUTOMATION_DB_* env (local fallback)
 */
final class SocialAutomationDatabaseConnectionService
{
    private static ?string $bootstrappedFingerprint = null;

    public function __construct(
        private readonly ServiceDatabaseConnectionResolver $resolver,
    ) {}

    public function connectionName(): string
    {
        return SocialAutomationServiceConfig::CONNECTION;
    }

    public function bootstrap(bool $forceReconnect = false): void
    {
        if ($this->resolver->tryBootstrap(SocialAutomationServiceResolver::SLUG, $forceReconnect)) {
            return;
        }

        $config = $this->envFallbackConfig();
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
        $core = $this->resolver->health(SocialAutomationServiceResolver::SLUG);
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

        try {
            $this->bootstrap(forceReconnect: true);
        } catch (Throwable) {
            return [
                'source' => 'env',
                'connection' => $name,
                'database' => (string) config('database.connections.'.$name.'.database', ''),
                'configured' => false,
                'reachable' => false,
                'error' => 'bootstrap_failed',
            ];
        }

        $database = (string) config('database.connections.'.$name.'.database', '');
        if ($database === '' || strcasecmp($database, 'omi_seo_ai') === 0 || strcasecmp($database, 'omi_seeding') === 0) {
            return [
                'source' => 'env',
                'connection' => $name,
                'database' => $database,
                'configured' => $this->envLooksConfigured(),
                'reachable' => false,
                'error' => $database === '' ? 'database_empty' : 'invalid_database_name',
            ];
        }

        $configured = $this->envLooksConfigured();

        try {
            DB::connection($name)->getPdo();
            DB::connection($name)->select('select 1 as ok');

            return [
                'source' => 'env',
                'connection' => $name,
                'database' => $database,
                'configured' => true,
                'reachable' => true,
                'error' => null,
            ];
        } catch (Throwable) {
            return [
                'source' => 'env',
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
    private function envFallbackConfig(): array
    {
        $existing = Config::get('database.connections.'.$this->connectionName(), []);
        if (! is_array($existing) || $existing === []) {
            $mysql = Config::get('database.connections.mysql', []);
            $existing = is_array($mysql) ? $mysql : [];
        }

        $database = (string) ($existing['database'] ?? env('SOCIAL_AUTOMATION_DB_DATABASE', 'omi_social_automation'));
        if ($database === '' || strcasecmp($database, 'omi_seo_ai') === 0 || strcasecmp($database, 'omi_seeding') === 0) {
            $database = 'omi_social_automation';
        }

        return array_merge($existing, [
            'driver' => DriverAwareConnectionConfig::normalizeDriver($existing['driver'] ?? null),
            'database' => $database,
        ]);
    }

    private function envLooksConfigured(): bool
    {
        return filled(env('SOCIAL_AUTOMATION_DB_URL'))
            || filled(env('SOCIAL_AUTOMATION_DB_DATABASE'))
            || filled(env('SOCIAL_AUTOMATION_DB_HOST'));
    }
}
