<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Config;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Tests\TestCase;

final class SeoDatabaseConnectionConfigPortabilityTest extends TestCase
{
    public function test_manual_mysql_keeps_charset_and_forced_mysql_is_gone(): void
    {
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        $connection = new SeoDatabaseConnection([
            'type' => 'manual',
            'host' => 'db.example.test',
            'port' => '3307',
            'database' => 'seo_custom',
            'username' => 'seo_user',
        ]);
        $connection->password = 'secret';

        $resolved = (new SeoDatabaseConnectionService)->resolveConnectionArrayFromModel($connection);

        $this->assertSame('mysql', $resolved['driver']);
        $this->assertSame('3307', (string) $resolved['port']);
        $this->assertSame('utf8mb4_unicode_ci', $resolved['collation']);
        $this->assertSame('secret', $resolved['password']);
    }

    public function test_manual_honors_pgsql_driver(): void
    {
        Config::set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        $connection = new SeoDatabaseConnection([
            'type' => 'manual',
            'host' => 'pg.example.test',
            'database' => 'seo_custom',
            'username' => 'seo_user',
        ]);
        $connection->setAttribute('driver', 'pgsql');
        $connection->password = 'secret';

        $resolved = (new SeoDatabaseConnectionService)->resolveConnectionArrayFromModel($connection);

        $this->assertSame('pgsql', $resolved['driver']);
        $this->assertSame('5432', $resolved['port']);
        $this->assertArrayNotHasKey('collation', $resolved);
        $this->assertArrayNotHasKey('strict', $resolved);
    }
}
