<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceContextService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentWorkspaceContextTest extends TestCase
{
    public function test_with_site_clears_project_workspace_and_article(): void
    {
        $context = new AgentWorkspaceContext(
            tenantRef: 'tenant:cps_abc',
            siteRef: 'cps_abc',
            tenantId: 1,
            siteId: 5,
            connectionId: 10,
            siteName: 'Site A',
            actorRef: 'user:1',
            actorUserId: 1,
            role: 'manager',
            scopes: ['content-project:read'],
            projectRef: 'cpp_old',
            workspaceRef: 'cpw_old',
            articleRef: 'cpa_old',
            operationRef: 'cpo_old',
            projectPhase: 'review',
        );

        $switched = $context->withSite('cps_new', 9, 'Site B', 11);

        self::assertSame('cps_new', $switched->siteRef);
        self::assertSame(9, $switched->siteId);
        self::assertSame('Site B', $switched->siteName);
        self::assertNull($switched->projectRef);
        self::assertNull($switched->workspaceRef);
        self::assertNull($switched->articleRef);
        self::assertNull($switched->operationRef);
        self::assertNull($switched->projectPhase);
    }

    public function test_to_availability_context_includes_scopes(): void
    {
        $context = new AgentWorkspaceContext(
            tenantRef: 'tenant:cps_abc',
            siteRef: 'cps_abc',
            tenantId: 1,
            siteId: 5,
            connectionId: null,
            siteName: 'Site A',
            actorRef: 'user:1',
            actorUserId: 1,
            role: 'manager',
            scopes: ['content-project:read', 'content-project:write'],
            providers: ['serp' => true],
        );

        $availability = $context->toAvailabilityContext();

        self::assertSame(['content-project:read', 'content-project:write'], $availability['scopes']);
        self::assertSame('cps_abc', $availability['site_ref']);
        self::assertSame(['serp' => true], $availability['providers']);
        self::assertSame('manager', $availability['role']);
    }

    public function test_context_service_rejects_cross_site_refs(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceContextService::class))->getFileName(),
        );

        self::assertStringContainsString('agent.context.project_site_mismatch', $source);
        self::assertStringContainsString('agent.context.article_site_mismatch', $source);
        self::assertStringContainsString('canAccessSite', $source);
        self::assertStringContainsString('assertProjectBelongsToSite', $source);
        self::assertStringContainsString('assertArticleBelongsToSite', $source);
    }
}
