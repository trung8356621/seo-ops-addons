<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackCapabilityBinder;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackCompatibilityService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackCompiler;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackConstants;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackImportExportService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackManifestValidator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackSafeMappingValidator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs\AgentPackSafeSchemaValidator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\BuiltinAgentEvaluationDatasetInstaller;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\BuiltinSkillCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\PackSkills;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentPackPhase7Test extends TestCase
{
    private AgentPackManifestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AgentPackManifestValidator;
    }

    public function test_valid_builtin_manifest(): void
    {
        $r = $this->validator->validate([
            'schema_version' => '1',
            'key' => 'omi.agent-core',
            'name' => 'Core',
            'version' => '1.0.0',
            'type' => 'builtin',
            'skills' => [],
        ], 'builtin');
        self::assertTrue($r['ok']);
    }

    public function test_valid_custom_manifest(): void
    {
        $r = $this->validator->validate([
            'schema_version' => '1',
            'key' => 'acme.reports',
            'name' => 'Reports',
            'version' => '1.2.3',
            'type' => 'custom',
            'skills' => [],
        ], 'custom');
        self::assertTrue($r['ok']);
    }

    public function test_invalid_key(): void
    {
        $r = $this->validator->validate([
            'schema_version' => '1',
            'key' => '../evil',
            'name' => 'X',
            'version' => '1.0.0',
        ]);
        self::assertFalse($r['ok']);
        self::assertNotEmpty(array_filter($r['errors'], static fn (string $e): bool => str_contains($e, 'invalid_key')));
    }

    public function test_invalid_semantic_version(): void
    {
        $r = $this->validator->validate([
            'schema_version' => '1',
            'key' => 'acme.reports',
            'name' => 'X',
            'version' => 'v1',
        ]);
        self::assertFalse($r['ok']);
        self::assertContains('invalid_semantic_version', $r['errors']);
    }

    public function test_unsupported_schema(): void
    {
        $r = $this->validator->validate([
            'schema_version' => '99',
            'key' => 'acme.reports',
            'name' => 'X',
            'version' => '1.0.0',
        ]);
        self::assertFalse($r['ok']);
        self::assertTrue((bool) array_filter($r['errors'], static fn (string $e): bool => str_starts_with($e, 'unsupported_schema')));
    }

    public function test_sdk_incompatibility(): void
    {
        $r = $this->validator->validate([
            'schema_version' => '1',
            'key' => 'acme.reports',
            'name' => 'X',
            'version' => '1.0.0',
            'sdk_constraint' => 99,
        ]);
        self::assertFalse($r['ok']);
        self::assertContains('sdk_incompatible', $r['errors']);
    }

    public function test_workspace_incompatibility(): void
    {
        $r = $this->validator->validate([
            'schema_version' => '1',
            'key' => 'acme.reports',
            'name' => 'X',
            'version' => '1.0.0',
            'agent_workspace_constraint' => '^3.0.0',
        ]);
        self::assertFalse($r['ok']);
        self::assertContains('workspace_incompatible', $r['errors']);
    }

    public function test_core_namespace_takeover_rejected_for_custom(): void
    {
        $r = $this->validator->validate([
            'schema_version' => '1',
            'key' => 'omi.hijack',
            'name' => 'X',
            'version' => '1.0.0',
        ], 'custom');
        self::assertFalse($r['ok']);
        self::assertContains('core_namespace_takeover', $r['errors']);
    }
}

final class AgentPackCompatibilityTest extends TestCase
{
    public function test_missing_dependency(): void
    {
        $svc = new AgentPackCompatibilityService;
        $r = $svc->check([
            'key' => 'acme.a',
            'dependencies' => ['acme.missing'],
            'conflicts' => [],
        ], []);
        self::assertFalse($r['ok']);
        self::assertContains('missing_dependency:acme.missing', $r['errors']);
    }

    public function test_circular_dependency(): void
    {
        $svc = new AgentPackCompatibilityService;
        $r = $svc->check([
            'key' => 'acme.a',
            'dependencies' => ['acme.b'],
            'conflicts' => [],
        ], [
            'acme.b' => ['status' => 'enabled', 'dependencies' => ['acme.a']],
        ]);
        self::assertFalse($r['ok']);
        self::assertContains('circular_dependency', $r['errors']);
    }

    public function test_active_conflict(): void
    {
        $svc = new AgentPackCompatibilityService;
        $r = $svc->check([
            'key' => 'acme.a',
            'dependencies' => [],
            'conflicts' => ['acme.b'],
        ], [
            'acme.b' => ['status' => 'enabled'],
        ]);
        self::assertFalse($r['ok']);
        self::assertContains('active_conflict:acme.b', $r['errors']);
    }

    public function test_deterministic_load_order(): void
    {
        $svc = new AgentPackCompatibilityService;
        $r = $svc->check([
            'key' => 'acme.a',
            'dependencies' => ['acme.b'],
            'conflicts' => [],
        ], [
            'acme.b' => ['status' => 'enabled', 'dependencies' => []],
        ]);
        self::assertTrue($r['ok']);
        self::assertSame(['acme.b', 'acme.a'], $r['order']);
    }
}

final class AgentPackSkillBindingTest extends TestCase
{
    private function binder(array $caps): AgentPackCapabilityBinder
    {
        return new AgentPackCapabilityBinder(static fn (string $name): ?array => $caps[$name] ?? null);
    }

    public function test_valid_capability_binding(): void
    {
        $r = $this->binder([
            'content_project.get_summary' => [
                'name' => 'content_project.get_summary',
                'risk_level' => 'read',
                'confirmation_requirement' => false,
                'input_schema' => [],
                'exposed' => true,
            ],
        ])->bind([
            'key' => 'acme.reports.summary',
            'command' => '/acme-summary',
            'capability' => 'content_project.get_summary',
            'mode' => 'read',
            'confirmation_policy' => 'none',
            'input_schema' => ['properties' => []],
        ], [], [], 'acme.reports');
        self::assertTrue($r['ok']);
    }

    public function test_unknown_capability(): void
    {
        $r = $this->binder([])->bind([
            'key' => 'acme.reports.x',
            'command' => '/acme-x',
            'capability' => 'missing.cap',
            'mode' => 'read',
        ], [], [], 'acme.reports');
        self::assertFalse($r['ok']);
        self::assertContains('skill.unknown_capability', $r['errors']);
    }

    public function test_internal_capability_rejected(): void
    {
        $r = $this->binder([
            'secret.internal' => ['internal' => true, 'risk_level' => 'read', 'confirmation_requirement' => false],
        ])->bind([
            'key' => 'acme.reports.x',
            'command' => '/acme-x',
            'capability' => 'secret.internal',
            'mode' => 'read',
        ], [], [], 'acme.reports');
        self::assertFalse($r['ok']);
        self::assertContains('skill.internal_capability_rejected', $r['errors']);
    }

    public function test_mode_mismatch(): void
    {
        $r = $this->binder([
            'content_project.write' => ['risk_level' => 'write', 'confirmation_requirement' => true, 'exposed' => true],
        ])->bind([
            'key' => 'acme.reports.x',
            'command' => '/acme-x',
            'capability' => 'content_project.write',
            'mode' => 'read',
            'confirmation_policy' => 'confirm',
        ], [], [], 'acme.reports');
        self::assertFalse($r['ok']);
        self::assertContains('skill.mode_mismatch', $r['errors']);
    }

    public function test_confirmation_downgrade_rejected(): void
    {
        $r = $this->binder([
            'content_project.write' => ['risk_level' => 'write', 'confirmation_requirement' => true, 'exposed' => true],
        ])->bind([
            'key' => 'acme.reports.x',
            'command' => '/acme-x',
            'capability' => 'content_project.write',
            'mode' => 'write',
            'confirmation_policy' => 'none',
        ], [], [], 'acme.reports');
        self::assertFalse($r['ok']);
        self::assertContains('skill.confirmation_downgrade', $r['errors']);
    }

    public function test_core_override_rejected(): void
    {
        $r = $this->binder([
            'content_project.get_summary' => ['risk_level' => 'read', 'confirmation_requirement' => false, 'exposed' => true],
        ])->bind([
            'key' => 'agent.help',
            'command' => '/help-hijack',
            'capability' => 'content_project.get_summary',
            'mode' => 'read',
        ], [], [], 'acme.reports');
        self::assertFalse($r['ok']);
        self::assertContains('skill.core_override_forbidden', $r['errors']);
    }

    public function test_slash_and_alias_conflict(): void
    {
        $r = $this->binder([
            'content_project.get_summary' => ['risk_level' => 'read', 'confirmation_requirement' => false, 'exposed' => true],
        ])->bind([
            'key' => 'acme.reports.x',
            'command' => '/help',
            'capability' => 'content_project.get_summary',
            'mode' => 'read',
            'aliases' => ['/agent-health'],
        ], ['/help', '/agent-health'], [], 'acme.reports');
        self::assertFalse($r['ok']);
        self::assertTrue((bool) array_filter($r['errors'], static fn (string $e): bool => str_contains($e, 'conflict')));
    }

    public function test_unsafe_transformer_and_secret_mapping(): void
    {
        $map = new AgentPackSafeMappingValidator;
        $bad = $map->validateInputMapping([
            'x' => ['source' => '$input.title', 'transform' => 'eval'],
        ]);
        self::assertFalse($bad['ok']);
        $secret = $map->validateInputMapping([
            'api_key' => '$input.api_key',
        ]);
        self::assertFalse($secret['ok']);
    }

    public function test_output_mapping_gateway_payload_only(): void
    {
        $map = new AgentPackSafeMappingValidator;
        $bad = $map->validateOutputMapping(['title' => '$db.articles.title']);
        self::assertFalse($bad['ok']);
        $ok = $map->validateOutputMapping(['title' => '$result.payload.title']);
        self::assertTrue($ok['ok']);
    }

    public function test_invalid_input_field_schema(): void
    {
        $schema = new AgentPackSafeSchemaValidator;
        $r = $schema->validate([
            'properties' => [
                'bad field' => ['type' => 'string'],
            ],
        ]);
        self::assertFalse($r['ok']);
    }

    public function test_arbitrary_regex_rejected(): void
    {
        $schema = new AgentPackSafeSchemaValidator;
        $r = $schema->validate([
            'properties' => [
                'slug' => ['type' => 'string', 'pattern' => '.*'],
            ],
        ]);
        self::assertFalse($r['ok']);
    }
}

final class AgentPackCompilerTest extends TestCase
{
    public function test_no_partial_compile_on_skill_error(): void
    {
        $binder = new AgentPackCapabilityBinder(static fn (): ?array => null);
        $compiler = new AgentPackCompiler(binder: $binder);
        $r = $compiler->compile([
            'schema_version' => '1',
            'key' => 'acme.reports',
            'name' => 'Reports',
            'version' => '1.0.0',
            'type' => 'custom',
            'skills' => [[
                'key' => 'acme.reports.x',
                'command' => '/acme-x',
                'capability' => 'missing',
            ]],
            'templates' => [],
        ]);
        self::assertFalse($r['ok']);
        self::assertArrayNotHasKey('compiled', $r);
    }

    public function test_pack_dataset_namespace(): void
    {
        $binder = new AgentPackCapabilityBinder(static fn (string $n): ?array => [
            'name' => $n,
            'risk_level' => 'read',
            'confirmation_requirement' => false,
            'exposed' => true,
            'input_schema' => [],
        ]);
        $compiler = new AgentPackCompiler(binder: $binder);
        $r = $compiler->compile([
            'schema_version' => '1',
            'key' => 'acme.reports',
            'name' => 'Reports',
            'version' => '1.0.0',
            'type' => 'custom',
            'skills' => [],
            'templates' => [],
            'evaluation_datasets' => [['key' => 'routing', 'cases' => []]],
        ]);
        self::assertTrue($r['ok']);
        self::assertSame('pack:acme.reports:routing', $r['compiled']['evaluation_datasets'][0]['key']);
    }
}

final class AgentPackImportTest extends TestCase
{
    public function test_export_excludes_secrets(): void
    {
        $svc = new AgentPackImportExportService;
        $exp = $svc->exportDeclarative([
            'key' => 'acme.reports',
            'api_key' => 'secret',
            'skills' => [],
            'history' => ['x'],
        ]);
        self::assertTrue($exp['ok']);
        $json = (string) $exp['json'];
        self::assertStringNotContainsString('secret', $json);
        self::assertStringNotContainsString('history', $json);
    }

    public function test_oversize_rejected(): void
    {
        $svc = new AgentPackImportExportService;
        $r = $svc->importJson(str_repeat('a', AgentPackImportExportService::MAX_BYTES + 10), 1);
        self::assertFalse($r['ok']);
        self::assertSame('oversize', $r['code']);
    }

    public function test_php_in_zip_rejected(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive missing');
        }
        $svc = new AgentPackImportExportService;
        $tmp = tempnam(sys_get_temp_dir(), 'z');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('evil.php', '<?php echo 1;');
        $zip->addFromString('pack.json', '{"format":"omi-agent-pack","manifest":{}}');
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        $r = $svc->importZip($bin, 1);
        self::assertFalse($r['ok']);
        self::assertSame('executable_or_nested_archive', $r['code']);
    }

    public function test_traversal_rejected(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive missing');
        }
        $svc = new AgentPackImportExportService;
        $tmp = tempnam(sys_get_temp_dir(), 'z');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('../pack.json', '{}');
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        $r = $svc->importZip($bin, 1);
        self::assertFalse($r['ok']);
        self::assertSame('traversal', $r['code']);
    }
}

final class AgentPackEvaluationFreezeTest extends TestCase
{
    public function test_pack_skills_registered(): void
    {
        $cmds = array_column(PackSkills::definitions(), 'slash_command');
        foreach (['/agent-packs', '/pack-status', '/validate-pack', '/evaluate-pack', '/enable-pack', '/disable-pack', '/pack-skills'] as $c) {
            self::assertContains($c, $cmds);
        }
        $all = array_column(BuiltinSkillCatalog::definitions(), 'slash_command');
        self::assertContains('/agent-packs', $all);
    }

    public function test_orchestrator_no_command_bus(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AgentPackOrchestrator::class))->getFileName());
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('eval(', $source);
        self::assertStringNotContainsString('shell_exec', $source);
    }

    public function test_import_export_no_php_exec(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AgentPackImportExportService::class))->getFileName());
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringContainsString('executable_or_nested_archive', $source);
    }

    public function test_preview_never_executes(): void
    {
        $binder = new AgentPackCapabilityBinder(static fn (): ?array => [
            'risk_level' => 'read',
            'confirmation_requirement' => false,
            'exposed' => true,
            'input_schema' => [],
        ]);
        $compiler = new AgentPackCompiler(binder: $binder);
        // Direct studio preview shape
        $orchSource = (string) file_get_contents((new ReflectionClass(AgentPackOrchestrator::class))->getFileName());
        self::assertStringContainsString("'capability_executed' => false", $orchSource);
        self::assertStringContainsString("'executed' => false", $orchSource);
        unset($compiler);
    }

    public function test_constants_confirmation_rank(): void
    {
        self::assertGreaterThan(
            AgentPackConstants::confirmationRank('none'),
            AgentPackConstants::confirmationRank('confirm'),
        );
    }
}

final class BuiltinAgentDatasetTest extends TestCase
{
    public function test_catalog_contains_core_routing_and_security(): void
    {
        $installer = new BuiltinAgentEvaluationDatasetInstaller;
        $ref = new ReflectionClass($installer);
        $m = $ref->getMethod('catalog');
        $m->setAccessible(true);
        /** @var array<string, mixed> $catalog */
        $catalog = $m->invoke($installer);
        foreach ([
            'core-routing',
            'core-planning',
            'core-security',
            'core-execution-boundary',
            'core-knowledge-grounding',
            'core-automation-safety',
        ] as $key) {
            self::assertArrayHasKey($key, $catalog);
        }
        $caseKeys = array_column($catalog['core-routing']['cases'], 'case_key');
        self::assertContains('route.site_health', $caseKeys);
        self::assertNotEmpty($catalog['core-security']['cases']);
    }

    public function test_installer_command_registered_source(): void
    {
        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Console/InstallBuiltinAgentEvaluationsCommand.php',
        );
        self::assertStringContainsString('agent:evaluations:install-builtin', $cmd);
    }

    public function test_idempotency_documented_in_installer(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(BuiltinAgentEvaluationDatasetInstaller::class))->getFileName(),
        );
        self::assertStringContainsString('Does not overwrite manager-cloned/custom', $source);
        self::assertStringContainsString('case_key', $source);
    }
}

final class AgentPackUiPermissionTest extends TestCase
{
    public function test_packs_panel_exists_and_manager_gated(): void
    {
        $page = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Filament/Pages/AgentWorkspacePage.php',
        );
        self::assertStringContainsString('openPacksPanel', $page);
        self::assertStringContainsString('canAccessManagerFeatures', $page);
        self::assertStringContainsString('enablePack', $page);
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/pages/partials/agent-workspace/packs-panel.blade.php'),
        );
        self::assertStringContainsString('wire:confirm', $blade);
        self::assertStringContainsString('never executes', $blade);
    }
}
