<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentIntentRouter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceVersion;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\BuiltinAgentEvaluationDatasetInstaller;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\BuiltinSkillCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\ContentProjectSkills;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\V1\AgentCapabilityInventory;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\V1\AgentSkillGroupCatalog;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentCommandFactory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentWorkspaceV1SweepTest extends TestCase
{
    public function test_version_metadata(): void
    {
        $snap = AgentWorkspaceVersion::snapshot();
        self::assertSame('1.0.0', $snap['version']);
        self::assertSame('v1.0', $snap['freeze']);
        self::assertSame('1.0', $snap['pack_schema']);
    }

    public function test_freeze_doc_exists(): void
    {
        $path = ProjectRoot::path().'/docs/modules/AGENT_WORKSPACE.md';
        self::assertFileExists($path);
    }
}

final class AgentCapabilityCoverageTest extends TestCase
{
    public function test_inventory_loads_p0(): void
    {
        $rows = AgentCapabilityInventory::rows();
        self::assertNotEmpty($rows);
        $p0 = array_filter($rows, static fn (array $r): bool => ($r['priority'] ?? '') === 'P0');
        self::assertGreaterThanOrEqual(15, count($p0));
    }

    public function test_p0_skills_present_in_catalog(): void
    {
        $keys = array_column(ContentProjectSkills::definitions(), 'key');
        foreach ([
            'content_project.create',
            'content_project.add_items',
            'content_project.generate',
            'content_project.rerun',
            'content_project.start_review',
            'content_project.approve',
            'content_project.schedule',
            'content_project.move_schedule',
            'content_project.publish_now',
            'content_project.retry_publish',
            'content_project.skip_publish',
            'content_project.cancel_publish',
            'content_project.archive',
            'content_project.restore',
            'content_project.stop_execution',
            'content_project.resume_execution',
            'content_project.list_items',
            'content_project.timeline',
        ] as $key) {
            self::assertContains($key, $keys, $key);
        }
    }

    public function test_stop_resume_in_capability_registry_source(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectCapabilityRegistry::class))->getFileName(),
        );
        self::assertStringContainsString("'content_project.stop_execution'", $source);
        self::assertStringContainsString("'content_project.resume_execution'", $source);
    }

    public function test_command_factory_maps_stop_resume(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentCommandFactory::class))->getFileName(),
        );
        self::assertStringContainsString("'content_project.stop_execution'", $source);
        self::assertStringContainsString('StopProjectExecutionCommand', $source);
        self::assertStringContainsString('ResumeProjectExecutionCommand', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
    }

    public function test_destructive_archive_requires_confirm(): void
    {
        foreach (ContentProjectSkills::definitions() as $row) {
            if (($row['key'] ?? '') === 'content_project.archive') {
                self::assertSame('confirm', $row['confirmation_policy']);

                return;
            }
        }
        self::fail('archive skill missing');
    }

    public function test_sync_items_not_in_skill_catalog(): void
    {
        foreach (BuiltinSkillCatalog::definitions() as $row) {
            self::assertNotSame('content_project.sync_items', $row['capability'] ?? null);
        }
    }

    public function test_slash_commands_unique_in_builtin(): void
    {
        $cmds = [];
        foreach (BuiltinSkillCatalog::definitions() as $row) {
            $cmd = (string) ($row['slash_command'] ?? '');
            self::assertArrayNotHasKey($cmd, $cmds, 'duplicate '.$cmd);
            $cmds[$cmd] = true;
            foreach (($row['aliases'] ?? []) as $alias) {
                self::assertArrayNotHasKey((string) $alias, $cmds, 'alias conflict '.$alias);
                $cmds[(string) $alias] = true;
            }
        }
    }

    public function test_skill_groups_catalog(): void
    {
        $groups = AgentSkillGroupCatalog::groups();
        $keys = array_column($groups, 'key');
        self::assertContains('content_projects', $keys);
        self::assertContains('publishing', $keys);
        self::assertContains('packs', $keys);
    }
}

final class AgentV1DoctorTest extends TestCase
{
    public function test_doctor_command_registered(): void
    {
        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Console/AgentV1DoctorCommand.php',
        );
        self::assertStringContainsString('agent:v1:doctor', $cmd);
        self::assertStringContainsString('fix-safe', $cmd);
        self::assertStringNotContainsString('ContentProjectCommandBus', $cmd);
        self::assertStringNotContainsString('optimize:clear', $cmd);
    }

    public function test_audit_command_registered(): void
    {
        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Console/AgentCapabilitiesAuditCommand.php',
        );
        self::assertStringContainsString('agent:capabilities:audit', $cmd);
        self::assertStringContainsString('fail-on-critical', $cmd);
    }

    public function test_readiness_service_no_business_execution(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Services/AgentWorkspace/V1/AgentV1ReadinessService.php',
        );
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('AgentGateway', $source);
        self::assertStringContainsString('fixSafe', $source);
    }

    public function test_ui_readiness_action_exists(): void
    {
        $page = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Filament/Pages/AgentWorkspacePage.php',
        );
        self::assertStringContainsString('runV1ReadinessCheck', $page);
        self::assertStringContainsString('canAccessManagerFeatures', $page);
    }
}

final class AgentRoutingV1Test extends TestCase
{
    public function test_extra_rules_cover_vietnamese_p0_phrases(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentIntentRouter::class))->getFileName(),
        );
        foreach ([
            'táº¡o content project thÃ¡ng',
            'thÃªm keyword vÃ o project',
            'báº¯t Ä‘áº§u duyá»‡t',
            'xuáº¥t báº£n ngay',
            'dá»«ng quÃ¡ trÃ¬nh',
            'archive project',
            'cháº¡y láº¡i tá»« outline',
        ] as $phrase) {
            self::assertStringContainsString($phrase, $source);
        }
    }

    public function test_registry_boots_with_new_skills(): void
    {
        $registry = new AgentSkillRegistry(BuiltinSkillCatalog::definitions());
        self::assertNotNull($registry->get('content_project.stop_execution'));
        self::assertNotNull($registry->resolveSlashCommand('/resume-execution'));
        self::assertNotNull($registry->resolveSlashCommand('/move-schedule'));
    }
}

final class BuiltinAgentDatasetV1Test extends TestCase
{
    public function test_core_routing_expanded_and_coverage_dataset(): void
    {
        $installer = new BuiltinAgentEvaluationDatasetInstaller;
        $m = (new ReflectionClass($installer))->getMethod('catalog');
        $m->setAccessible(true);
        /** @var array<string, mixed> $catalog */
        $catalog = $m->invoke($installer);
        self::assertArrayHasKey('core-routing', $catalog);
        self::assertArrayHasKey('core-capability-coverage', $catalog);
        self::assertGreaterThanOrEqual(15, count($catalog['core-routing']['cases']));
        self::assertNotEmpty($catalog['core-capability-coverage']['cases']);
    }
}
