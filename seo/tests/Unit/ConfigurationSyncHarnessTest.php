<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\BlankInstallationDetector;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigHttpResult;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigProtocol;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigSyncCrypto;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigSyncHttpTransport;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncCoordinator;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncException;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncStateStore;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\GoldenBootstrapService;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\GoldenPackageInstaller;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\InstallationMachineCredential;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\LiveConfigurationComparer;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\OutboundConfigurationGuard;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\SeoEntitlementProbe;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\ConfigurationSyncAuthorization;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\ConfigurationCanonicalizer;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\GoldenPackagePath;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SeoSettingsBundleService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

final class ConfigurationSyncHarnessTest extends TestCase
{
    private string $secret;

    private string $statePath;

    private string $goldenRoot;

    private string $officialManifestHash;

    private RecordingSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = ConfigSyncCrypto::base64UrlEncode('machine-secret-secret!!');
        $this->statePath = sys_get_temp_dir().'/seo-g2-harness-'.uniqid('', true).'.json';
        $this->goldenRoot = sys_get_temp_dir().'/seo-g2-live-golden-'.uniqid('', true);
        mkdir($this->goldenRoot, 0775, true);
        $this->officialManifestHash = hash_file('sha256', GoldenPackagePath::manifest(base_path()));
        config([
            'configuration-sync.require_https' => false,
            'configuration-sync.base_url' => 'http://ops.test',
            'configuration-sync.client_version' => '12.50.0',
            'configuration-sync.addons_version' => '0.1.0',
            'configuration-sync.state_path' => $this->statePath,
            'configuration-sync.schedule_enabled' => false,
        ]);
        $this->installBlankIsolatedSchema();
        $this->sender = $this->makeSender();
    }

    protected function tearDown(): void
    {
        $this->assertSame($this->officialManifestHash, hash_file('sha256', GoldenPackagePath::manifest(base_path())));
        $this->deleteTree($this->goldenRoot);
        $this->deleteTree($this->goldenRoot.'.__previous');
        @unlink($this->statePath);
        @unlink($this->statePath.'.lock');
        parent::tearDown();
    }

    public function test_blank_bootstrap_imports_once_and_repeat_does_not_overwrite(): void
    {
        $this->assertSame(0, SeoPrompt::query()->count());
        $this->assertFalse(SeoAccessControl::canAccessManagerFeatures());

        $first = $this->bootstrap()->bootstrap(1, $this->goldenRoot);
        $this->assertSame('bootstrapped', $first['status'], json_encode($first));
        $this->assertSame(30, SeoPrompt::query()->count());
        $this->assertSame(3, SeoTask::query()->count());
        $promptsAfterFirst = SeoPrompt::query()->count();

        $second = $this->bootstrap()->bootstrap(1, $this->goldenRoot);
        $this->assertSame('existing_client', $second['status']);
        $this->assertFalse($second['imported']);
        $this->assertSame($promptsAfterFirst, SeoPrompt::query()->count());
    }

    public function test_overwrite_existing_reapplies_golden_without_duplicating_inventory(): void
    {
        $first = $this->bootstrap()->bootstrap(1, $this->goldenRoot);
        $this->assertSame('bootstrapped', $first['status'], json_encode($first));
        $promptCount = SeoPrompt::query()->count();
        $taskCount = SeoTask::query()->count();

        $prompt = SeoPrompt::query()->first();
        $this->assertNotNull($prompt);
        $prompt->markdown_content = 'customized-by-user';
        $prompt->save();

        $refused = $this->bootstrap()->bootstrap(1, $this->goldenRoot, false);
        $this->assertSame('existing_client', $refused['status']);
        $this->assertSame('customized-by-user', SeoPrompt::query()->whereKey($prompt->id)->value('markdown_content'));

        $overwritten = $this->bootstrap()->bootstrap(1, $this->goldenRoot, true);
        $this->assertSame('bootstrapped', $overwritten['status'], json_encode($overwritten));
        $this->assertSame($promptCount, SeoPrompt::query()->count());
        $this->assertSame($taskCount, SeoTask::query()->count());
        $this->assertNotSame('customized-by-user', SeoPrompt::query()->whereKey($prompt->id)->value('markdown_content'));
    }

    public function test_empty_prompt_table_with_settings_is_not_bootstrapped(): void
    {
        DB::table('wp_options')->insert([
            'option_name' => 'seo_content_language_settings',
            'option_value' => '{"default_content_language":"vi"}',
            'autoload' => 'no',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(0, SeoPrompt::query()->count());

        $result = $this->bootstrap()->bootstrap(1, $this->goldenRoot);
        $this->assertSame('existing_client', $result['status']);
        $this->assertTrue($result['signals']['settings']);
        $this->assertFalse($result['signals']['prompts']);
        $this->assertSame(0, SeoPrompt::query()->count());
        $this->assertSame([], $this->sender->calls);
    }

    public function test_live_export_detects_prompt_workflow_and_deletion_without_uploading_noise(): void
    {
        $boot = $this->bootstrap()->bootstrap(1, $this->goldenRoot);
        $this->assertSame('bootstrapped', $boot['status'], json_encode($boot));
        $this->assertFalse(SeoAccessControl::canAccessManagerFeatures());
        app(SeoSettingsBundleService::class)->exportForConfigurationSync(1, ConfigurationSyncAuthorization::forReadExport());

        $coordinator = $this->coordinator();
        $clean = $coordinator->compare(1, $this->goldenRoot);
        $this->assertSame('in_sync', $clean['outcome'], (string) $clean['reason']);

        $syncCalls = count($this->sender->calls);
        $unchanged = $coordinator->sync(1, $this->goldenRoot);
        $this->assertFalse($unchanged['uploaded']);
        $this->assertSame('in_sync', $unchanged['status']);
        foreach (array_slice($this->sender->calls, $syncCalls) as $call) {
            $this->assertStringEndsNotWith('/config/sync', $call['url']);
        }

        $prompt = SeoPrompt::query()->orderBy('id')->firstOrFail();
        $prompt->markdown_content = ((string) $prompt->markdown_content).'x';
        $prompt->save();
        $edited = $coordinator->compare(1, $this->goldenRoot);
        $this->assertSame('customized', $edited['outcome']);
        $this->assertSame('modified', $edited['diff']['prompts'][0]['change_type']);
        $this->assertNotSame($clean['current_hash'], $edited['current_hash']);

        $prompt->markdown_content = substr((string) $prompt->markdown_content, 0, -1);
        $prompt->save();
        $task = SeoTask::query()->orderBy('id')->firstOrFail();
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $flow['edges'] = array_merge($flow['edges'] ?? [], [[
            'id' => 'edge_g2_probe',
            'sourceNode' => 'missing_a',
            'sourcePort' => 'out',
            'targetNode' => 'missing_b',
            'targetPort' => 'in',
        ]]);
        $task->flow_data = $flow;
        $task->save();
        $graph = $coordinator->compare(1, $this->goldenRoot);
        $this->assertNotEmpty($graph['diff']['workflows']);
        $this->assertSame('modified', $graph['diff']['workflows'][0]['change_type']);

        $uuid = (string) $prompt->portable_uuid;
        $prompt->delete();
        $deleted = $coordinator->compare(1, $this->goldenRoot);
        $deletedUuids = array_column($deleted['diff']['prompts'], 'portable_uuid');
        $this->assertContains($uuid, $deletedUuids);
    }

    public function test_changed_upload_and_retry_keep_the_same_body_and_idempotency_key(): void
    {
        $this->bootstrap()->bootstrap(1, $this->goldenRoot);
        $prompt = SeoPrompt::query()->orderBy('id')->firstOrFail();
        $prompt->markdown_content = ((string) $prompt->markdown_content).'x';
        $prompt->save();
        $this->sender->syncFailures = 1;

        $first = $this->coordinator()->sync(1, $this->goldenRoot);
        $this->assertSame('deferred', $first['status']);
        $this->assertFalse($first['uploaded']);
        $uploadCalls = array_values(array_filter(
            $this->sender->calls,
            static fn (array $call): bool => str_ends_with($call['url'], '/config/sync'),
        ));
        $this->assertCount(1, $uploadCalls);
        $this->assertStringNotContainsString('api_key', $uploadCalls[0]['body']);
        $this->assertStringNotContainsString($this->secret, $uploadCalls[0]['body']);
        $this->assertStringNotContainsString('machine-secret-secret!!', $uploadCalls[0]['body']);
        $stateRaw = (string) file_get_contents($this->statePath);
        $this->assertStringNotContainsString($this->secret, $stateRaw);

        $state = new ConfigurationSyncStateStore($this->statePath);
        $stored = $state->read();
        $stored['pending']['next_attempt_at'] = gmdate('c', time() - 5);
        $state->write($stored);

        $second = $this->coordinator()->sync(1, $this->goldenRoot);
        $this->assertTrue($second['uploaded'], json_encode($second));
        $uploadCalls = array_values(array_filter(
            $this->sender->calls,
            static fn (array $call): bool => str_ends_with($call['url'], '/config/sync'),
        ));
        $this->assertCount(2, $uploadCalls);
        $this->assertSame($uploadCalls[0]['body'], $uploadCalls[1]['body']);
        $this->assertSame($uploadCalls[0]['headers']['X-Ops-Content-SHA256'], $uploadCalls[1]['headers']['X-Ops-Content-SHA256']);
        $decoded = json_decode($uploadCalls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($decoded['idempotency_key'], json_decode($uploadCalls[1]['body'], true, 512, JSON_THROW_ON_ERROR)['idempotency_key']);
        $this->assertSame('duplicate', $second['ack']['status']);
        $this->assertSame('modified', $decoded['diff']['prompts'][0]['change_type']);
        (new OutboundConfigurationGuard())->assertUploadable($this->guardShape($decoded));
    }

    public function test_server_offline_does_not_run_from_the_settings_exporter(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(SeoSettingsBundleService::class))->getFileName());
        $this->assertStringNotContainsString('ConfigSyncHttpTransport', $source);
        $this->assertStringNotContainsString('GoldenBootstrapService', $source);
        $detector = new BlankInstallationDetector(new ConfigurationSyncStateStore($this->statePath));
        $this->assertTrue($detector->assess(1)['blank']);
    }

    private function bootstrap(): GoldenBootstrapService
    {
        $state = new ConfigurationSyncStateStore($this->statePath);
        $credentials = new FixedCredentialProvider(new InstallationMachineCredential('cred_01', 'ins_01', $this->secret));

        return new GoldenBootstrapService(
            $credentials,
            new EnabledSeoEntitlement(),
            new ConfigSyncHttpTransport($credentials, $this->sender, $state),
            new GoldenPackageInstaller(),
            new BlankInstallationDetector($state),
            $state,
            app(SeoSettingsBundleService::class),
        );
    }

    private function coordinator(): ConfigurationSyncCoordinator
    {
        $state = new ConfigurationSyncStateStore($this->statePath);
        $credentials = new FixedCredentialProvider(new InstallationMachineCredential('cred_01', 'ins_01', $this->secret));

        return new ConfigurationSyncCoordinator(
            $credentials,
            new ConfigSyncHttpTransport($credentials, $this->sender, $state),
            new LiveConfigurationComparer(),
            new OutboundConfigurationGuard(),
            $state,
            app(SeoSettingsBundleService::class),
        );
    }

    private function makeSender(): RecordingSender
    {
        $bundle = $this->bundleFromGolden();

        return new RecordingSender(function (string $method, string $url, array $headers, string $body) use ($bundle): ConfigHttpResult {
            if (str_ends_with($url, '/config/sync')) {
                if ($this->sender->syncFailures > 0) {
                    $this->sender->syncFailures--;
                    throw new ConfigurationSyncException('server_offline', 'OPS-SERVER configuration endpoint is unreachable.');
                }
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                return $this->signed(201, [
                    'protocol' => ConfigProtocol::ID,
                    'receipt_id' => 'cfg_rev_test',
                    'installation_id' => 'ins_01',
                    'service' => 'seo',
                    'status' => 'duplicate',
                    'sync_status' => 'customized',
                    'idempotency_key' => (string) ($decoded['idempotency_key'] ?? ''),
                ], $headers);
            }
            if (str_ends_with($url, '/config/status')) {
                return $this->signed(200, [
                    'protocol' => ConfigProtocol::ID,
                    'status' => 'acknowledged',
                    'sync_status' => 'in_sync',
                ], $headers);
            }
            if (str_ends_with($url, '/config/package')) {
                return $this->signed(200, $bundle, $headers);
            }

            return $this->signed(200, [
                'protocol' => ConfigProtocol::ID,
                'status' => 'published',
                'service' => 'seo',
                'package' => [
                    'package_id' => $bundle['package_id'],
                    'package_version' => $bundle['package_version'],
                    'canonical_hash' => $bundle['canonical_hash'],
                    'compatibility' => ['client' => '12.50.0', 'addons' => '0.1.0'],
                ],
            ], $headers);
        });
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function signed(int $status, array $body, array $headers): ConfigHttpResult
    {
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($raw);
        $digest = ConfigSyncCrypto::contentSha256($raw);
        $timestamp = ConfigSyncCrypto::timestamp();
        $nonce = ConfigSyncCrypto::nonce();

        return new ConfigHttpResult($status, $raw, [
            'X-Ops-Protocol' => ConfigProtocol::ID,
            'X-Ops-Credential-Id' => 'cred_01',
            'X-Ops-Timestamp' => $timestamp,
            'X-Ops-Nonce' => $nonce,
            'X-Ops-Content-SHA256' => $digest,
            'X-Ops-Signature' => ConfigSyncCrypto::sign(ConfigSyncCrypto::responseSigningString(
                $status,
                (string) $headers['X-Ops-Nonce'],
                $timestamp,
                $nonce,
                $digest,
            ), $this->secret),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bundleFromGolden(): array
    {
        $root = GoldenPackagePath::absolute(base_path());
        $files = [];
        foreach (ConfigProtocol::ALLOWED_FILES as $relative) {
            $files[$relative] = (string) file_get_contents($root.'/'.$relative);
        }
        $manifest = json_decode($files['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($files['payload/full-configuration.json'], true, 512, JSON_THROW_ON_ERROR);

        return [
            'protocol' => ConfigProtocol::ID,
            'package_id' => $manifest['package_id'],
            'package_version' => $manifest['package_version'],
            'configuration_schema_version' => $manifest['configuration_schema_version'],
            'service' => 'seo',
            'canonical_hash' => (new ConfigurationCanonicalizer())->fingerprint($payload),
            'entrypoint' => 'payload/full-configuration.json',
            'compatibility' => $manifest['compatibility'],
            'manifest' => $manifest,
            'checksums' => json_decode($files['metadata/checksums.json'], true, 512, JSON_THROW_ON_ERROR),
            'files' => $files,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function guardShape(array $decoded): array
    {
        if ($decoded['diff']['settings'] instanceof \stdClass || $decoded['diff']['settings'] === []) {
            $decoded['diff']['settings'] = [];
        }

        return $decoded;
    }

    private function installBlankIsolatedSchema(): void
    {
        $conn = (string) config('database.default');
        $this->assertSame('sqlite', $conn);
        Schema::connection($conn)->dropIfExists('wp_options');
        Schema::connection($conn)->create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        WpOption::clearRequestCache();
        Schema::connection($conn)->dropIfExists('prompts');
        Schema::connection($conn)->create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->longText('markdown_content')->nullable();
            $table->string('hook_key')->nullable();
            $table->string('hook_version')->nullable();
            $table->json('hook_settings')->nullable();
            $table->json('variables')->nullable();
            $table->json('settings')->nullable();
            $table->string('tools')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_flow_prompt')->default(false);
            $table->string('routing_mode')->nullable();
            $table->string('routing_profile_key')->nullable();
            $table->unsignedBigInteger('ai_connection_id')->nullable();
            $table->uuid('portable_uuid')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::connection($conn)->dropIfExists('seo_tasks');
        Schema::connection($conn)->create('seo_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('flow_data')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('output_type')->nullable();
            $table->char('portable_uuid', 36)->nullable();
            $table->timestamps();
        });
        Schema::connection($conn)->dropIfExists('api_connections');
        Schema::connection($conn)->create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('provider')->index();
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->string('default_model')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection($conn)->dropIfExists('ai_routing_targets');
        Schema::connection($conn)->dropIfExists('ai_routing_profiles');
        Schema::connection($conn)->create('ai_routing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('key', 64);
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('required_capabilities')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::connection($conn)->create('ai_routing_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->string('profile_key', 64)->index();
            $table->unsignedBigInteger('api_connection_id')->index();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable()->index();
            $table->string('model_key', 128);
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });
        Schema::connection($conn)->dropIfExists('ai_provider_templates');
        Schema::connection($conn)->create('ai_provider_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('provider_key')->index();
            $table->string('name');
            $table->string('protocol');
            $table->string('schema_version')->default('1.0');
            $table->json('config');
            $table->boolean('is_builtin')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        DB::connection($conn)->table('prompts')->delete();
        DB::connection($conn)->table('seo_tasks')->delete();
        DB::connection($conn)->table('wp_options')->delete();
        DB::connection($conn)->table('api_connections')->delete();
        WpOption::clearRequestCache();
        $payload = json_decode((string) file_get_contents(GoldenPackagePath::entrypoint(base_path())), true, 512, JSON_THROW_ON_ERROR);
        $hooks = [];
        foreach ($payload['prompts']['prompts'] ?? [] as $row) {
            if (is_array($row)) {
                $hook = trim((string) ($row['hook'] ?? ''));
                if ($hook !== '') {
                    $hooks[] = $hook;
                }
            }
        }
        app()->instance(PromptPackService::class, new PromptPackService(knownHookKeys: array_values(array_unique($hooks))));
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}

final class EnabledSeoEntitlement implements SeoEntitlementProbe
{
    public function seoServiceEnabled(): bool
    {
        return true;
    }
}
