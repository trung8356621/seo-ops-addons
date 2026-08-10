<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\SeoDatabaseConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SeoDatabaseConnectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_connection_uses_model_database_or_generated_name(): void
    {
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'username' => 'root',
            'password' => '',
        ]);

        $connection = new SeoDatabaseConnection([
            'type' => 'auto',
            'database' => 'omi_seo_ai',
        ]);
        $connection->id = 3;

        $service = new SeoDatabaseConnectionService;
        $resolved = $service->resolveConnectionArrayFromModel($connection);

        $this->assertSame('omi_seo_ai', $resolved['database']);
    }

    public function test_manual_connection_uses_model_fields(): void
    {
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'username' => 'root',
            'password' => '',
        ]);

        $connection = new SeoDatabaseConnection([
            'type' => 'manual',
            'host' => 'db.example.test',
            'port' => '3307',
            'database' => 'seo_custom',
            'username' => 'seo_user',
        ]);
        $connection->password = 'secret';

        $service = new SeoDatabaseConnectionService;
        $resolved = $service->resolveConnectionArrayFromModel($connection);

        $this->assertSame('db.example.test', $resolved['host']);
        $this->assertSame('seo_custom', $resolved['database']);
        $this->assertSame('seo_user', $resolved['username']);
        $this->assertSame('secret', $resolved['password']);
    }

    public function test_hash_format_validation(): void
    {
        $this->assertTrue(SeoConnectionContext::isValidHashFormat(str_repeat('a', 32)));
        $this->assertFalse(SeoConnectionContext::isValidHashFormat('short'));
        $this->assertFalse(SeoConnectionContext::isValidHashFormat('invalid-chars!'));
    }

    public function test_bootstrap_legacy_shared_connection_uses_manual_record_from_core_table(): void
    {
        Config::set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'username' => 'core_user',
            'password' => 'core-pass',
        ]);

        $record = SeoDatabaseConnection::query()->create([
            'name' => 'Hosting SEO',
            'hash_id' => str_repeat('b', 32),
            'type' => 'manual',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'lzxzdusj_omi_seo_ai',
            'username' => 'lzxzdusj_omi_seo_ai',
            'password' => 'seo-pass',
            'is_active' => true,
        ]);

        $service = new SeoDatabaseConnectionService;
        $service->bootstrapLegacySharedConnection();

        $resolved = Config::get('database.connections.omi_seo_ai');
        $this->assertSame('lzxzdusj_omi_seo_ai', $resolved['database']);
        $this->assertSame('lzxzdusj_omi_seo_ai', $resolved['username']);
        $this->assertSame('seo-pass', $resolved['password']);
        $this->assertSame($record->id, $service->resolveDefaultSharedConnectionRecord()?->id);
    }
}
