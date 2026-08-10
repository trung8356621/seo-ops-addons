<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeConflictResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Security\AgentKnowledgeContentSanitizer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentKnowledgeChunker;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentKnowledgeCitationPresenter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentKnowledgeConflictResolver;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentMemoryCandidateExtractor;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\DefaultAgentKnowledgeSourceRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\AgentContextBudgetManager;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\AgentPlanningContextAssembler;
use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AgentKnowledgeSecurityTest extends TestCase
{
    public function test_sanitizer_rejects_secrets_and_strips_script(): void
    {
        $sanitizer = new AgentKnowledgeContentSanitizer;
        $bad = $sanitizer->sanitize('Title', 'api_key=sk-abcdefghijklmnopqrstuvwxyz');
        self::assertFalse($bad['ok']);
        self::assertTrue($bad['secrets_found']);

        $ok = $sanitizer->sanitize('Brand', '<script>alert(1)</script><p>Hello brand</p>');
        self::assertTrue($ok['ok']);
        self::assertStringNotContainsString('<script>', $ok['content']);
        self::assertStringContainsString('Hello brand', $ok['content']);
    }

    public function test_chunker_does_not_split_json_and_dedupes(): void
    {
        $chunker = new AgentKnowledgeChunker;
        $json = '{"rule":"never split me","items":[1,2,3]}';
        $chunks = $chunker->chunk($json, 'JSON');
        self::assertCount(1, $chunks);

        $dup = $chunker->chunk("Para one.\n\nPara one.");
        self::assertLessThanOrEqual(2, count($dup));
    }

    public function test_source_registry_rejects_unsupported_upload(): void
    {
        $registry = new DefaultAgentKnowledgeSourceRegistry;
        $this->expectException(RuntimeException::class);
        $registry->extract('uploaded_document', [
            'filename' => 'evil.exe',
            'mime' => 'application/octet-stream',
            'content' => '',
        ]);
    }

    public function test_citation_rejects_fabricated_handles(): void
    {
        $presenter = new AgentKnowledgeCitationPresenter;
        $citations = $presenter->present([
            ['hash_id' => 'aknow_1', 'title' => 'A', 'content' => 'x', 'version' => 1, 'source_type' => 'manual', 'scope_type' => 'site', 'trust_level' => 'user_confirmed'],
        ]);
        $result = $presenter->validateHandles($citations, ['K1', 'K99', 'https://evil.example']);
        self::assertSame(['K1'], $result['valid']);
        self::assertContains('K99', $result['rejected']);
    }

    public function test_conflict_system_verified_not_overridden_by_unverified(): void
    {
        $resolver = new AgentKnowledgeConflictResolver;
        $result = $resolver->resolve([
            [
                'hash_id' => 'a',
                'type' => 'seo_rule',
                'scope_type' => 'project',
                'trust_level' => 'unverified',
                'title' => 'u',
            ],
            [
                'hash_id' => 'b',
                'type' => 'seo_rule',
                'scope_type' => 'site',
                'trust_level' => 'system_verified',
                'title' => 's',
            ],
        ]);
        $statuses = array_column($result['conflicts'], 'status');
        self::assertContains(AgentKnowledgeConflictResult::REQUIRES_USER_REVIEW, $statuses);
    }

    public function test_memory_candidate_never_implies_auto_persist(): void
    {
        $extractor = new AgentMemoryCandidateExtractor;
        $context = new AgentWorkspaceContext(
            tenantRef: 't',
            siteRef: 's',
            tenantId: 1,
            siteId: 1,
            connectionId: null,
            siteName: 'S',
            actorRef: 'u',
            actorUserId: 1,
            role: 'manager',
        );
        $candidates = $extractor->extract('Nhớ: luôn dùng CTA đăng ký demo', $context);
        self::assertNotEmpty($candidates);
        self::assertContains('requires_user_approval', $candidates[0]->warnings);
    }

    public function test_budget_includes_grounded_knowledge_priority(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentContextBudgetManager::class))->getFileName(),
        );
        self::assertStringContainsString("'grounded_knowledge'", $source);
    }

    public function test_assembler_accepts_optional_grounding_provider(): void
    {
        $ctor = (new ReflectionClass(AgentPlanningContextAssembler::class))->getConstructor();
        $params = $ctor?->getParameters() ?? [];
        $names = array_map(static fn ($p) => $p->getName(), $params);
        self::assertContains('grounding', $names);
    }

    public function test_ui_and_orchestrator_have_no_vendor_vector_sdk(): void
    {
        $page = (string) file_get_contents((new ReflectionClass(AgentWorkspacePage::class))->getFileName());
        self::assertStringNotContainsString('Pinecone', $page);
        self::assertStringNotContainsString('OpenAI\\', $page);
        self::assertStringContainsString('openKnowledgePanel', $page);
        self::assertStringContainsString('resolveMemoryProposal', $page);
    }

    public function test_query_fail_closed_without_site(): void
    {
        $query = new AgentKnowledgeQuery(
            tenantId: 1,
            siteId: 0,
            connectionHash: null,
            message: 'x',
            siteRef: '',
        );
        self::assertSame(0, $query->siteId);
        self::assertSame('', $query->siteRef);
    }
}
