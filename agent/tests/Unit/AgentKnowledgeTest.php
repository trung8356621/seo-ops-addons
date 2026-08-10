<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentKnowledgeConflictResolver;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentMemoryCandidateExtractor;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\DefaultAgentKnowledgeOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\DefaultAgentGroundingContextProvider;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\BuiltinSkillCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\KnowledgeSkills;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentKnowledgeTest extends TestCase
{
    public function test_knowledge_skills_registered_in_catalog(): void
    {
        $keys = array_column(BuiltinSkillCatalog::definitions(), 'key');
        foreach (['knowledge.list', 'knowledge.add', 'knowledge.search', 'knowledge.forget', 'knowledge.verify', 'knowledge.review_memory'] as $key) {
            self::assertContains($key, $keys);
        }
        $slash = array_column(KnowledgeSkills::definitions(), 'slash_command');
        self::assertContains('/knowledge', $slash);
        self::assertContains('/add-knowledge', $slash);
        self::assertContains('/forget-memory', $slash);
    }

    public function test_application_service_routes_knowledge_not_command_bus(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceApplicationService::class))->getFileName(),
        );
        self::assertStringContainsString('AgentKnowledgeOrchestrator', $source);
        self::assertStringContainsString('handleKnowledge', $source);
        self::assertStringNotContainsString('ContentProjectCommandBus', $source);
        self::assertStringContainsString('proposeMemory', $source);
    }

    public function test_orchestrator_execution_fact_guard_in_source(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentKnowledgeOrchestrator::class))->getFileName(),
        );
        self::assertStringContainsString('execution_not_succeeded', $source);
        self::assertStringContainsString('duplicate_content', $source);
        self::assertStringContainsString('secret_detected', $source);
        self::assertStringContainsString('business_deleted', $source);
    }

    public function test_grounding_provider_fail_soft(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(DefaultAgentGroundingContextProvider::class))->getFileName(),
        );
        self::assertStringContainsString('grounding_failed', $source);
        self::assertStringContainsString('grounding_skipped_missing_site', $source);
    }

    public function test_project_override_conflict_status(): void
    {
        $resolver = new AgentKnowledgeConflictResolver;
        $out = $resolver->resolve([
            ['hash_id' => 'p', 'type' => 'tone', 'scope_type' => 'project', 'trust_level' => 'user_confirmed'],
            ['hash_id' => 's', 'type' => 'tone', 'scope_type' => 'site', 'trust_level' => 'user_confirmed'],
        ]);
        self::assertCount(1, $out['items']);
        self::assertSame('p', $out['items'][0]['hash_id']);
        self::assertSame('scope_override', $out['conflicts'][0]['status']);
    }

    public function test_extractor_empty_without_signal(): void
    {
        $extractor = new AgentMemoryCandidateExtractor;
        $context = new \Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext(
            tenantRef: 't', siteRef: 's', tenantId: 1, siteId: 1, connectionId: null,
            siteName: 'S', actorRef: 'u', actorUserId: 1, role: 'manager',
        );
        self::assertSame([], $extractor->extract('xin chào', $context));
    }
}
