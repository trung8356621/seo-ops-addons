<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentMemoryCandidateExtractor;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services\AgentMemoryProposalService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AgentMemoryTest extends TestCase
{
    public function test_candidate_extractor_requires_approval_warning(): void
    {
        $extractor = new AgentMemoryCandidateExtractor;
        $ctx = new AgentWorkspaceContext(
            tenantRef: 't', siteRef: 's', tenantId: 1, siteId: 1, connectionId: null,
            siteName: 'S', actorRef: 'u', actorUserId: 9, role: 'manager', projectRef: 'cp_1',
        );
        $c = $extractor->extract('Remember: always mention free trial', $ctx);
        self::assertNotEmpty($c);
        self::assertContains('requires_user_approval', $c[0]->warnings);
    }

    public function test_proposal_edit_allowlist_only(): void
    {
        $method = new ReflectionMethod(AgentMemoryProposalService::class, 'applyEdits');
        self::assertTrue($method->isPublic());
        $source = (string) file_get_contents((new ReflectionClass(AgentMemoryProposalService::class))->getFileName());
        self::assertStringContainsString('EDIT_ALLOWLIST', $source);
        self::assertStringContainsString('proposed_scope_type', $source);
        self::assertMatchesRegularExpression(
            "/EDIT_ALLOWLIST\s*=\s*\[[^\]]*\]/s",
            $source,
        );
        // Browser edit allowlist must not include site_id (server binds site from context).
        self::assertDoesNotMatchRegularExpression(
            "/EDIT_ALLOWLIST\s*=\s*\[[^\]]*site_id[^\]]*\]/s",
            $source,
        );
    }

    public function test_application_service_exposes_proposal_resolve_without_auto_persist_flag(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceApplicationService::class))->getFileName(),
        );
        self::assertStringContainsString('resolveMemoryProposal', $source);
        self::assertStringContainsString('proposeMemory', $source);
        self::assertStringNotContainsString('auto_persist', $source);
    }
}
