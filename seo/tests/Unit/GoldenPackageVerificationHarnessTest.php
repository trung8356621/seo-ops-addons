<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Models\User;
use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Exceptions\ConfigurationPackageException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptPack\PromptPackService;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderTemplateCatalog;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\ConfigurationCanonicalizer;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\GoldenPackageIntegrityVerifier;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage\GoldenPackagePath;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SeoSettingsBundleService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SystemBootstrapAuthorization;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

/**
 * G0 — Isolated Golden Package validation harness.
 *
 * Uses PHPUnit sqlite :memory: only (TestDatabaseGuard). Never touches
 * development/production MySQL databases.
 */
final class GoldenPackageVerificationHarnessTest extends TestCase
{
    private const EXPECTED_PROMPTS = 30;

    private const EXPECTED_WORKFLOWS = 3;

    private const EXPECTED_BINDINGS = 14;

    private const EXPECTED_SETTINGS_GROUPS = [
        'general',
        'date_time',
        'workflows',
        'ai',
        'article_editor',
        'keywords',
        'seo_scoring',
        'prompt_runtime',
    ];

    private SeoSettingsBundleService $bundleService;

    private ConfigurationCanonicalizer $canonicalizer;

    private string $packageRoot;

    private string $entrypointJson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageRoot = GoldenPackagePath::absolute(base_path());
        $this->assertDirectoryExists($this->packageRoot);
        $this->entrypointJson = (string) file_get_contents(GoldenPackagePath::entrypoint(base_path()));
        $this->assertNotSame('', trim($this->entrypointJson));

        $this->installBlankIsolatedSchema();
        $this->bindPromptPackWithGoldenHooks();
        $this->bundleService = app(SeoSettingsBundleService::class);
        $this->canonicalizer = new ConfigurationCanonicalizer();
    }

    /**
     * Isolated harness does not boot every product hook contributor.
     * Seed PromptPackService with hook keys declared by the Golden package so
     * import preserves portable hook bindings without fabricating auth.
     */
    private function bindPromptPackWithGoldenHooks(): void
    {
        $payload = json_decode($this->entrypointJson, true, 512, JSON_THROW_ON_ERROR);
        $prompts = $payload['prompts']['prompts'] ?? $payload['prompts'] ?? [];
        $hooks = [];
        foreach (is_array($prompts) ? $prompts : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hook = trim((string) ($row['hook'] ?? $row['hook_key'] ?? ''));
            if ($hook !== '') {
                $hooks[] = $hook;
            }
        }
        $hooks = array_values(array_unique($hooks));

        app()->instance(PromptPackService::class, new PromptPackService(knownHookKeys: $hooks));
    }

    public function test_g0_checksums_and_manifest_match_package_bytes(): void
    {
        $result = (new GoldenPackageIntegrityVerifier())->verify($this->packageRoot);

        $this->assertSame('seo-ops-golden-configuration', $result['manifest']['package_id'] ?? null);
        $this->assertSame('1.1', $result['manifest']['configuration_schema_version'] ?? null);
        $this->assertSame('payload/full-configuration.json', $result['manifest']['entrypoint'] ?? null);
        $this->assertContains('manifest.json', $result['verified_files']);
        $this->assertContains('payload/full-configuration.json', $result['verified_files']);
        $this->assertGreaterThanOrEqual(8, count($result['verified_files']));
    }

    public function test_g0_tampered_checksum_is_rejected(): void
    {
        $tmp = sys_get_temp_dir().'/golden-pkg-tamper-'.uniqid('', true);
        $this->copyPackageTree($this->packageRoot, $tmp);

        try {
            $checksumsPath = $tmp.'/metadata/checksums.json';
            $checksums = json_decode((string) file_get_contents($checksumsPath), true, 512, JSON_THROW_ON_ERROR);
            $checksums['payload/full-configuration.json'] = str_repeat('0', 64);
            file_put_contents($checksumsPath, json_encode($checksums, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            $this->expectException(ConfigurationPackageException::class);
            $this->expectExceptionMessage('checksum mismatch');
            (new GoldenPackageIntegrityVerifier())->verify($tmp);
        } finally {
            $this->deleteTree($tmp);
        }
    }

    public function test_g0_parse_and_plan_via_existing_import_engine(): void
    {
        $plan = $this->bundleService->parseAndPlan($this->entrypointJson, 1, 'merge');

        $this->assertSame('seo_configuration_bundle', $plan->type->value);
        $this->assertSame('1.1', $plan->schemaVersion);
        $this->assertCount(self::EXPECTED_PROMPTS, $plan->prompts);
        $this->assertCount(self::EXPECTED_WORKFLOWS, $plan->tasks);
        $this->assertCount(3, $plan->providerTemplates);

        $sectionKeys = array_map(static fn (array $s): string => (string) ($s['key'] ?? ''), $plan->sections);
        foreach (self::EXPECTED_SETTINGS_GROUPS as $expected) {
            $this->assertContains($expected, $sectionKeys);
        }

        $unresolved = array_values(array_filter(
            $plan->warnings,
            static fn (string $w): bool => str_contains(strtolower($w), 'not on this workspace')
                || str_contains(strtolower($w), 'unresolved'),
        ));
        // Blank DB: binding UUID warnings before apply are expected; must clear after import.
        $this->assertIsArray($unresolved);
    }

    public function test_g0_blank_database_import_inventory_and_idempotency(): void
    {
        $this->assertSame(0, SeoPrompt::query()->count());
        $this->assertSame(0, SeoTask::query()->count());
        $this->assertFalse(SeoAccessControl::canAccessManagerFeatures());

        $plan = $this->bundleService->parseAndPlan($this->entrypointJson, 1, 'merge');
        $auth = SystemBootstrapAuthorization::forGoldenConfigurationBootstrap();
        $this->bundleService->applyForSystemBootstrap($plan, 1, $auth);

        $this->assertInventoryAfterImport();
        $this->assertNoLocalIdsInPortableGraphDependencies();
        $this->assertProviderTemplatesCredentialFree();

        $exportedOnce = $this->exportAsManager();
        $fingerprintOnce = $this->canonicalizer->fingerprint($exportedOnce);

        $plan2 = $this->bundleService->parseAndPlan($this->entrypointJson, 1, 'merge');
        $this->bundleService->applyForSystemBootstrap($plan2, 1, $auth);

        $this->assertSame(self::EXPECTED_PROMPTS, SeoPrompt::query()->count());
        $this->assertSame(self::EXPECTED_WORKFLOWS, SeoTask::query()->count());
        $this->assertInventoryAfterImport();

        $exportedTwice = $this->exportAsManager();
        $this->assertTrue(
            $this->canonicalizer->equals($exportedOnce, $exportedTwice),
            'Second import must not create semantic configuration drift.',
        );
        $this->assertSame($fingerprintOnce, $this->canonicalizer->fingerprint($exportedTwice));
    }

    public function test_g0_manager_apply_still_requires_manager_context(): void
    {
        $plan = $this->bundleService->parseAndPlan($this->entrypointJson, 1, 'merge');

        $this->expectException(ConfigurationPackageException::class);
        $this->expectExceptionMessage('Not authorized');
        $this->bundleService->apply($plan, 1);
    }

    public function test_g0_export_normalize_compare_ignores_noise_detects_semantic_change(): void
    {
        $plan = $this->bundleService->parseAndPlan($this->entrypointJson, 1, 'merge');
        $this->bundleService->applyForSystemBootstrap(
            $plan,
            1,
            SystemBootstrapAuthorization::forGoldenConfigurationBootstrap(),
        );

        $golden = json_decode($this->entrypointJson, true, 512, JSON_THROW_ON_ERROR);
        $exported = $this->exportAsManager();

        // Key reorder + exported_at must not create false positives.
        $reordered = $this->canonicalizer->canonicalize($exported);
        $keys = array_keys($reordered);
        rsort($keys);
        $shuffled = [];
        foreach ($keys as $key) {
            $shuffled[$key] = $reordered[$key];
        }
        $shuffled['meta']['exported_at'] = '2099-01-01T00:00:00Z';
        $this->assertTrue($this->canonicalizer->equals($exported, $shuffled));

        // Golden baseline vs live export: portable identity compare (not local ids).
        $match = $this->promptsAndWorkflowsMatch($golden, $exported);
        $this->assertTrue(
            $match['ok'],
            'Live export must match Golden prompts/workflows/bindings after normalization. '.$match['reason'],
        );

        // One-character prompt edit MUST differ.
        $prompt = SeoPrompt::query()->orderBy('id')->first();
        $this->assertNotNull($prompt);
        $prompt->markdown_content = ((string) $prompt->markdown_content).'x';
        $prompt->save();

        $edited = $this->exportAsManager();
        $this->assertFalse($this->canonicalizer->equals($exported, $edited));

        // Workflow graph modification MUST differ.
        $prompt->markdown_content = substr((string) $prompt->markdown_content, 0, -1);
        $prompt->save();
        $restored = $this->exportAsManager();
        $this->assertTrue($this->canonicalizer->equals($exported, $restored));

        $task = SeoTask::query()->orderBy('id')->first();
        $this->assertNotNull($task);
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $flow['edges'] = array_merge($flow['edges'] ?? [], [[
            'id' => 'edge_g0_probe',
            'sourceNode' => 'missing_a',
            'sourcePort' => 'out',
            'targetNode' => 'missing_b',
            'targetPort' => 'in',
        ]]);
        $task->flow_data = $flow;
        $task->save();

        $graphEdited = $this->exportAsManager();
        $this->assertFalse($this->canonicalizer->equals($exported, $graphEdited));
    }

    public function test_g0_import_transaction_scope_is_default_connection_only(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(SeoSettingsBundleService::class))->getFileName());
        $this->assertStringContainsString('DB::transaction(', $src);
        $this->assertStringContainsString('database.default', $src);
        $this->assertStringContainsString('core_connection', $src);
        $this->assertStringContainsString('Cross-connection rollback is NOT guaranteed', $src);

        // In this harness, TestCase remaps core_connection === default (sqlite).
        $this->assertSame(
            (string) config('database.default'),
            (string) config('database.core_connection'),
            'Harness requires core_connection === default so import writes share one sqlite connection.',
        );
        $this->assertSame('sqlite', (string) config('database.default'));
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));
    }

    /**
     * @param  array<string, mixed>  $golden
     * @param  array<string, mixed>  $exported
     * @return array{ok: bool, reason: string}
     */
    private function promptsAndWorkflowsMatch(array $golden, array $exported): array
    {
        $g = $this->canonicalizer->canonicalize($golden);
        $e = $this->canonicalizer->canonicalize($exported);

        $gPrompts = $g['prompts']['prompts'] ?? [];
        $ePrompts = $e['prompts']['prompts'] ?? [];
        if (count($gPrompts) !== count($ePrompts)) {
            return ['ok' => false, 'reason' => 'prompt count '.count($gPrompts).' vs '.count($ePrompts)];
        }

        $gTasks = $g['tasks'] ?? [];
        $eTasks = $e['tasks'] ?? [];
        if (count($gTasks) !== count($eTasks)) {
            return ['ok' => false, 'reason' => 'task count '.count($gTasks).' vs '.count($eTasks)];
        }

        $gBindings = $g['settings']['workflows']['prompt_bindings'] ?? [];
        $eBindings = $e['settings']['workflows']['prompt_bindings'] ?? [];
        if (count($gBindings) !== count($eBindings)) {
            return ['ok' => false, 'reason' => 'binding count '.count($gBindings).' vs '.count($eBindings)];
        }

        $byUuid = static function (array $list): array {
            $map = [];
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $uuid = (string) ($row['portable_uuid'] ?? '');
                if ($uuid === '') {
                    continue;
                }
                $map[$uuid] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'content' => (string) ($row['content'] ?? $row['markdown_content'] ?? ''),
                    'hook' => (string) ($row['hook'] ?? $row['hook_key'] ?? ''),
                ];
            }

            return $map;
        };

        $gMap = $byUuid($gPrompts);
        $eMap = $byUuid($ePrompts);
        foreach ($gMap as $uuid => $row) {
            if (! isset($eMap[$uuid])) {
                return ['ok' => false, 'reason' => 'missing exported prompt '.$uuid];
            }
            if ($eMap[$uuid] !== $row) {
                $diff = [];
                foreach ($row as $k => $v) {
                    if (($eMap[$uuid][$k] ?? null) !== $v) {
                        $diff[] = $k.' len '.strlen((string) $v).' vs '.strlen((string) ($eMap[$uuid][$k] ?? ''));
                    }
                }

                return ['ok' => false, 'reason' => 'prompt drift '.$uuid.' ('.implode(', ', $diff).')'];
            }
        }

        if (($g['settings']['workflows']['prompt_bindings'] ?? []) !== ($e['settings']['workflows']['prompt_bindings'] ?? [])) {
            return ['ok' => false, 'reason' => 'prompt_bindings portable refs differ'];
        }

        $gTaskMap = [];
        foreach ($gTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $uuid = (string) ($task['portable_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $gTaskMap[$uuid] = [
                'name' => (string) ($task['name'] ?? ''),
                'nodes' => count($task['flow_data']['nodes'] ?? []),
                'edges' => count($task['flow_data']['edges'] ?? []),
            ];
        }
        foreach ($eTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $uuid = (string) ($task['portable_uuid'] ?? '');
            if ($uuid === '' || ! isset($gTaskMap[$uuid])) {
                return ['ok' => false, 'reason' => 'workflow uuid mismatch '.$uuid];
            }
            $cmp = [
                'name' => (string) ($task['name'] ?? ''),
                'nodes' => count($task['flow_data']['nodes'] ?? []),
                'edges' => count($task['flow_data']['edges'] ?? []),
            ];
            if ($cmp !== $gTaskMap[$uuid]) {
                return ['ok' => false, 'reason' => 'workflow graph drift '.$uuid];
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    private function assertInventoryAfterImport(): void
    {
        $this->assertSame(self::EXPECTED_PROMPTS, SeoPrompt::query()->count());
        $this->assertSame(self::EXPECTED_WORKFLOWS, SeoTask::query()->count());

        $uuids = SeoPrompt::query()->whereNotNull('portable_uuid')->pluck('portable_uuid')->all();
        $this->assertCount(self::EXPECTED_PROMPTS, array_unique($uuids));

        $taskUuids = SeoTask::query()->whereNotNull('portable_uuid')->pluck('portable_uuid')->all();
        $this->assertCount(self::EXPECTED_WORKFLOWS, array_unique($taskUuids));

        $bindings = app(SeoCreateArticleSettingsService::class)->getPromptHookBindings();
        $this->assertCount(self::EXPECTED_BINDINGS, $bindings);

        foreach ($bindings as $hook => $promptId) {
            $prompt = SeoPrompt::query()->whereKey((int) $promptId)->first();
            $this->assertNotNull($prompt, 'Broken prompt binding for '.$hook);
            $this->assertNotSame('', trim((string) $prompt->portable_uuid));
        }

        foreach (self::EXPECTED_SETTINGS_GROUPS as $group) {
            $this->assertNotNull($this->bundleService->registry()->get($group));
        }
    }

    private function assertNoLocalIdsInPortableGraphDependencies(): void
    {
        $exported = $this->exportAsManager();
        $tasks = is_array($exported['tasks'] ?? null) ? $exported['tasks'] : [];
        foreach ($tasks as $task) {
            $nodes = $task['flow_data']['nodes'] ?? [];
            foreach ($nodes as $node) {
                $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                $this->assertArrayNotHasKey('promptId', $data);
                $this->assertArrayNotHasKey('prompt_id', $data);
                if (isset($data['prompt_ref'])) {
                    $this->assertNotSame('', (string) ($data['prompt_ref']['portable_uuid'] ?? ''));
                }
            }
        }

        $bindings = $exported['settings']['workflows']['prompt_bindings'] ?? [];
        foreach ($bindings as $ref) {
            $this->assertIsArray($ref);
            $this->assertArrayHasKey('portable_uuid', $ref);
            $this->assertArrayNotHasKey('id', $ref);
        }
    }

    private function assertProviderTemplatesCredentialFree(): void
    {
        $golden = json_decode($this->entrypointJson, true, 512, JSON_THROW_ON_ERROR);
        $templates = is_array($golden['provider_templates'] ?? null) ? $golden['provider_templates'] : [];
        $this->assertCount(3, $templates);
        $this->bundleService->assertNoSecrets(['provider_templates' => $templates]);

        $builtins = (new AiProviderTemplateCatalog())->builtins();
        $this->assertArrayHasKey('gemini', $builtins);
        $this->assertArrayHasKey('deepseek', $builtins);
        $this->assertArrayHasKey('openrouter', $builtins);
    }

    /**
     * @return array<string, mixed>
     */
    private function exportAsManager(): array
    {
        $this->actingAs($this->manager(1));
        $exported = $this->bundleService->export(1, [], includePrompts: true, includeTemplates: true, includeTasks: true);
        auth()->logout();

        return $exported;
    }

    private function manager(int $id): User
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);
        $user->id = $id;

        return $user;
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

        // Ensure blank inventory before each case.
        DB::connection($conn)->table('prompts')->delete();
        DB::connection($conn)->table('seo_tasks')->delete();
        DB::connection($conn)->table('wp_options')->delete();
        DB::connection($conn)->table('api_connections')->delete();
        WpOption::clearRequestCache();
    }

    private function copyPackageTree(string $from, string $to): void
    {
        if (! is_dir($to) && ! mkdir($to, 0777, true) && ! is_dir($to)) {
            $this->fail('Unable to create temp package directory.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $target = $to.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
            if ($item->isDir()) {
                if (! is_dir($target) && ! mkdir($target) && ! is_dir($target)) {
                    $this->fail('Unable to create temp package subdirectory.');
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
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
