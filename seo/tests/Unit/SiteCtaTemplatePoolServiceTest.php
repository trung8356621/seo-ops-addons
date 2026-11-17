<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Models\Site;
use App\Models\SiteMeta;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplateOutputValidator;
use Omnichannel\Addons\Seo\Services\CtaManager\SiteCtaTemplatePoolService;
use Tests\TestCase;

final class SiteCtaTemplatePoolServiceTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $default = (string) config('database.default', 'sqlite');
        Config::set('database.core_connection', $default);
        $this->createCoreTables();

        $this->owner = User::query()->create([
            'name' => 'CTA Owner',
            'email' => 'cta-pool-owner-'.uniqid('', true).'@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_site_without_pool_returns_empty_dynamic_list(): void
    {
        $site = $this->makeSite('empty-pool.test');
        $pool = app(SiteCtaTemplatePoolService::class)->getPool($site);

        self::assertSame((int) $site->id, $pool['site_id']);
        self::assertSame([], $pool['templates']);
        self::assertSame(1, $pool['next_slot_seq']);
        self::assertNull($pool['last_generated_at']);
    }

    public function test_append_then_delete_does_not_reuse_slot_ids(): void
    {
        $site = $this->makeSite('append-delete.test');
        $service = app(SiteCtaTemplatePoolService::class);

        $batch = [];
        for ($i = 1; $i <= 10; $i++) {
            $batch[] = ['intent' => 'info', 'content' => 'CTA body '.$i];
        }
        $pool = $service->appendBatch($site, $batch);
        self::assertCount(10, $pool['templates']);
        self::assertSame(11, $pool['next_slot_seq']);
        self::assertSame('CTA_5', $pool['templates'][4]['slot_id']);

        $afterDelete = $service->deleteSlot($site, 5);
        self::assertCount(9, $afterDelete['templates']);
        self::assertSame(11, $afterDelete['next_slot_seq']);
        self::assertFalse(collect($afterDelete['templates'])->contains(
            static fn (array $row): bool => (int) $row['slot'] === 5,
        ));

        $second = [];
        for ($i = 1; $i <= 10; $i++) {
            $second[] = ['intent' => 'info', 'content' => 'Second batch '.$i];
        }
        $appended = $service->appendBatch($site, $second);
        self::assertCount(19, $appended['templates']);
        self::assertSame(21, $appended['next_slot_seq']);
        self::assertSame('CTA_11', $appended['templates'][9]['slot_id']);
        self::assertFalse(collect($appended['templates'])->contains(
            static fn (array $row): bool => (int) $row['slot'] === 5,
        ));
    }

    public function test_site_isolation_between_two_sites(): void
    {
        $a = $this->makeSite('cta-a.test');
        $b = $this->makeSite('cta-b.test');
        $service = app(SiteCtaTemplatePoolService::class);

        $service->appendBatch($a, [['content' => 'CTA site A']]);
        $service->appendBatch($b, [['content' => 'CTA site B']]);

        self::assertSame('CTA site A', $service->getPool($a)['templates'][0]['content']);
        self::assertSame('CTA site B', $service->getPool($b)['templates'][0]['content']);
    }

    public function test_append_skips_duplicate_content_and_never_overwrites(): void
    {
        $site = $this->makeSite('overwrite.test');
        $service = app(SiteCtaTemplatePoolService::class);
        $service->appendBatch($site, [['content' => 'Manual keep']]);
        $service->saveSlot($site, 1, ['content' => 'Manual keep', 'source' => 'manual']);

        $pool = $service->appendBatch($site, [
            ['content' => 'Manual keep'],
            ['content' => 'Fresh CTA'],
        ]);

        self::assertCount(2, $pool['templates']);
        self::assertSame('Manual keep', $pool['templates'][0]['content']);
        self::assertSame(SiteCtaTemplatePoolService::STATUS_MANUAL, $pool['templates'][0]['status']);
        self::assertSame('Fresh CTA', $pool['templates'][1]['content']);
    }

    public function test_v1_fixed_pool_migrates_without_empty_padding(): void
    {
        $site = $this->makeSite('legacy-v1.test');
        $templates = [];
        for ($i = 1; $i <= 10; $i++) {
            $templates[] = [
                'slot' => $i,
                'slot_id' => 'CTA_'.$i,
                'intent' => $i <= 2 ? 'info' : '',
                'content' => $i <= 2 ? 'Legacy '.$i : '',
                'status' => $i <= 2 ? 'ai' : 'empty',
                'source' => $i <= 2 ? 'ai' : '',
                'updated_at' => null,
                'generated_at' => null,
            ];
        }
        SiteMeta::query()->create([
            'site_id' => $site->id,
            'meta_key' => SiteCtaTemplatePoolService::META_KEY,
            'meta_value' => json_encode([
                'version' => 1,
                'site_id' => $site->id,
                'templates' => $templates,
                'last_generated_at' => null,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $pool = app(SiteCtaTemplatePoolService::class)->getPool($site);
        self::assertCount(2, $pool['templates']);
        self::assertSame(11, $pool['next_slot_seq']);
        self::assertSame(2, $pool['version']);
    }

    public function test_validator_accepts_exact_batch_and_rejects_duplicates_against_existing(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['slot' => $i, 'intent' => 'consultation', 'content' => 'Template '.$i];
        }
        $ok = (new SiteCtaTemplateOutputValidator)->validate(['templates' => $rows]);
        self::assertTrue($ok['ok']);
        self::assertCount(10, $ok['templates']);

        $dup = (new SiteCtaTemplateOutputValidator)->validate(
            ['templates' => $rows],
            ['Template 1'],
        );
        self::assertFalse($dup['ok']);
    }

    public function test_domain_resource_registers_cta_settings_route(): void
    {
        $pages = DomainResource::getPages();
        self::assertArrayHasKey('cta-settings', $pages);
        $addonsRoot = dirname(__DIR__, 3);
        $src = (string) file_get_contents(
            $addonsRoot.DIRECTORY_SEPARATOR.'search-foundation'
            .DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Filament'
            .DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'DomainResource.php',
        );
        self::assertStringContainsString("ManageDomainCtaTemplates::route('/{record}/cta-settings')", $src);
    }

    public function test_prompt_hook_v2_exists_with_existing_ctas_binding(): void
    {
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'ai-prompt'
            .DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'prompt-hooks'
            .DIRECTORY_SEPARATOR.'v01'.DIRECTORY_SEPARATOR.'site.cta.templates.generate@0.2.0.json';
        self::assertFileExists($path);
        $spec = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($spec);
        self::assertSame('site.cta.templates.generate', $spec['key']);
        self::assertSame('0.2.0', $spec['version']);
        self::assertArrayHasKey('existing_ctas', $spec['input_schema']);
        self::assertStringContainsString('APPEND', (string) ($spec['canonical_default']['markdown'] ?? ''));
    }

    private function makeSite(string $domain): Site
    {
        return Site::query()->create([
            'user_id' => $this->owner->id,
            'domain' => $domain,
            'status' => 'active',
        ]);
    }

    private function createCoreTables(): void
    {
        Schema::dropIfExists('site_meta');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('role')->nullable();
            $table->string('status')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('domain');
            $table->string('status')->nullable();
            $table->boolean('ssl')->default(false);
            $table->boolean('h1_is_anchor')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('meta_key');
            $table->longText('meta_value')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'meta_key']);
        });
    }
}
