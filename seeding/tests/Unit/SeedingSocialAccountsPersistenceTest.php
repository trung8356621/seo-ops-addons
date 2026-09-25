<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialAccountStatus;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Models\SeedingSocialAccount;
use Omnichannel\Addons\Seeding\Services\SeedingSocialAccountService;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Live encryption + status + active read-model persistence on omi_seeding plane.
 */
final class SeedingSocialAccountsPersistenceTest extends TestCase
{
    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = SeedingServiceConfig::CONNECTION;
        $this->bootSqliteMemoryConnection();

        try {
            DB::connection($this->connection)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('omi_seeding unavailable: '.$e->getMessage());
        }

        $this->ensureTable();
        DB::connection($this->connection)->table('seeding_social_accounts')->delete();
    }

    private function bootSqliteMemoryConnection(): void
    {
        Config::set('database.connections.'.$this->connection, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge($this->connection);
    }

    private function ensureTable(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seeding_social_accounts')) {
            return;
        }

        $schema->create('seeding_social_accounts', function ($table): void {
            $table->id();
            $table->string('installation_id', 64)->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('domain', 255);
            $table->string('platform', 32);
            $table->string('label', 255)->nullable();
            $table->text('username_encrypted')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->json('meta_json')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('credential_updated_at')->nullable();
            $table->timestamps();
        });
    }

    private function service(): SeedingSocialAccountService
    {
        return app(SeedingSocialAccountService::class);
    }

    public function test_credentials_encrypted_at_rest_and_decrypt_via_model(): void
    {
        $service = $this->service();
        $row = $service->create(7, [
            'domain' => 'congtybalo.com',
            'platform' => 'facebook',
            'label' => 'Marketing',
            'username' => 'marketing@example.com',
            'password' => 's3cret-plain',
            'status' => 'active',
        ]);

        self::assertSame(SeedingSocialPlatform::Facebook, $row->platform);
        self::assertSame(SeedingSocialAccountStatus::Active, $row->status);
        self::assertSame('congtybalo.com', $row->domain);
        self::assertNull($row->site_id);
        self::assertNotSame('', (string) $row->installation_id);

        $raw = DB::connection($this->connection)
            ->table('seeding_social_accounts')
            ->where('id', $row->id)
            ->first();
        self::assertNotNull($raw);
        self::assertNotSame('marketing@example.com', (string) $raw->username_encrypted);
        self::assertNotSame('s3cret-plain', (string) $raw->password_encrypted);
        self::assertNotSame('', (string) $raw->username_encrypted);
        self::assertNotSame('', (string) $raw->password_encrypted);

        $fresh = SeedingSocialAccount::query()->find($row->id);
        self::assertInstanceOf(SeedingSocialAccount::class, $fresh);
        self::assertSame('marketing@example.com', $fresh->username_encrypted);
        self::assertSame('s3cret-plain', $fresh->password_encrypted);

        $serialized = $fresh->toArray();
        self::assertArrayNotHasKey('username_encrypted', $serialized);
        self::assertArrayNotHasKey('password_encrypted', $serialized);
        self::assertArrayNotHasKey('password', $serialized);

        $api = $fresh->toManagerApiArray();
        self::assertSame('marketing@example.com', $api['username']);
        self::assertTrue($api['has_username']);
        self::assertTrue($api['has_password']);
        self::assertArrayNotHasKey('password', $api);
        self::assertArrayNotHasKey('password_encrypted', $api);
    }

    public function test_blank_password_on_update_preserves_existing(): void
    {
        $service = $this->service();
        $row = $service->create(1, [
            'domain' => 'example.com',
            'platform' => 'threads',
            'username' => 'user1',
            'password' => 'keep-me',
        ]);

        $updated = $service->update(1, (int) $row->id, [
            'label' => 'Renamed',
            'password' => '',
            'username' => 'user1-updated',
        ]);

        self::assertSame('Renamed', $updated->label);
        self::assertSame('user1-updated', $updated->username_encrypted);
        self::assertSame('keep-me', $updated->password_encrypted);
        self::assertSame('keep-me', $service->copyPassword((int) $row->id));
    }

    public function test_nonblank_password_on_update_replaces(): void
    {
        $service = $this->service();
        $row = $service->create(1, [
            'domain' => 'example.com',
            'platform' => 'tiktok',
            'password' => 'old-pass',
        ]);

        $service->update(1, (int) $row->id, [
            'password' => 'new-pass',
        ]);

        self::assertSame('new-pass', $service->copyPassword((int) $row->id));
    }

    public function test_lock_preserves_credentials_and_excludes_from_active_read_model(): void
    {
        $service = $this->service();
        $active = $service->create(1, [
            'domain' => 'a.com',
            'platform' => 'facebook',
            'password' => 'p1',
            'status' => 'active',
        ]);
        $locked = $service->create(1, [
            'domain' => 'a.com',
            'platform' => 'threads',
            'password' => 'p2',
            'status' => 'active',
        ]);
        $service->lock(1, (int) $locked->id);

        $freshLocked = SeedingSocialAccount::query()->find($locked->id);
        self::assertSame(SeedingSocialAccountStatus::Locked, $freshLocked?->status);
        self::assertSame('p2', $freshLocked?->password_encrypted);

        $activeList = $service->activeForWorkspace();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $activeList);
        self::assertContains((int) $active->id, $ids);
        self::assertNotContains((int) $locked->id, $ids);

        foreach ($activeList as $item) {
            self::assertArrayNotHasKey('password', $item);
            self::assertArrayNotHasKey('username', $item);
            self::assertSame('active', $item['status']);
        }

        $service->unlock(1, (int) $locked->id);
        $idsAfter = array_map(
            static fn (array $r): int => (int) $r['id'],
            $service->activeForWorkspace()
        );
        self::assertContains((int) $locked->id, $idsAfter);
    }

    public function test_installation_scoping_rejects_cross_namespace(): void
    {
        $service = $this->service();
        $row = $service->create(1, [
            'domain' => 'scoped.com',
            'platform' => 'pinterest',
            'password' => 'x',
        ]);

        $row->installation_id = 'foreign-install-namespace';
        $row->save();

        $this->expectException(NotFoundHttpException::class);
        $service->copyPassword((int) $row->id);
    }

    public function test_site_id_and_domain_snapshot_persisted(): void
    {
        $service = $this->service();
        $row = $service->create(3, [
            'domain' => 'Snapshot.Example.COM',
            'platform' => 'reddit',
            'username' => 'r-user',
        ]);

        self::assertSame('snapshot.example.com', $row->domain);
        self::assertNull($row->site_id);

        $row->site_id = 42;
        $row->save();
        $fresh = SeedingSocialAccount::query()->find($row->id);
        self::assertSame(42, (int) $fresh?->site_id);
        self::assertSame('snapshot.example.com', $fresh?->domain);

        $forSite = $service->activeForSite(42);
        self::assertCount(1, $forSite);
        self::assertSame(42, $forSite[0]['site_id']);
        self::assertSame('reddit', $forSite[0]['platform']);
    }

    public function test_website_share_resolver_prefers_site_then_falls_back_to_normalized_domain_and_dedupes(): void
    {
        $installation = app(\Omnichannel\Addons\Seeding\Support\SeedingServiceResolver::class)->installationNamespace();
        SeedingSocialAccount::query()->create([
            'installation_id' => $installation,
            'site_id' => 7,
            'domain' => 'example.com',
            'platform' => 'facebook',
            'status' => 'active',
        ]);
        SeedingSocialAccount::query()->create([
            'installation_id' => $installation,
            'site_id' => 7,
            'domain' => 'example.com',
            'platform' => 'facebook',
            'status' => 'active',
        ]);
        SeedingSocialAccount::query()->create([
            'installation_id' => $installation,
            'site_id' => null,
            'domain' => 'example.com',
            'platform' => 'threads',
            'status' => 'active',
        ]);
        SeedingSocialAccount::query()->create([
            'installation_id' => $installation,
            'site_id' => null,
            'domain' => 'example.com',
            'platform' => 'pinterest',
            'status' => 'locked',
        ]);

        $service = $this->service();
        self::assertSame(['facebook'], $service->activePlatformsForWebsiteShare(7, 'https://www.example.com/'));
        self::assertSame(['facebook', 'threads'], $service->activePlatformsForWebsiteShare(null, 'https://www.example.com/'));
    }
}
