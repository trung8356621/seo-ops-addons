<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract: docs/modules/CONTENT_PROJECT_ARCHITECTURE.md is SSOT for domain ownership.
 */
final class ContentProjectArchitectureOwnershipContractTest extends TestCase
{
    public function test_canonical_architecture_doc_exists_and_states_invariants(): void
    {
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'docs'
            .DIRECTORY_SEPARATOR.'modules'
            .DIRECTORY_SEPARATOR.'CONTENT_PROJECT_ARCHITECTURE.md';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('task.site_id', $src);
        self::assertStringContainsString('domain-neutral', $src);
        self::assertStringContainsString('MUST NOT** change `task.site_id`', $src);
        self::assertStringContainsString('NOT** the canonical domain owner', $src);
        self::assertStringContainsString('null` `project.site_id` as invalid', $src);
    }

    public function test_agents_md_points_at_architecture_doc(): void
    {
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'AGENTS.md';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('docs/modules/CONTENT_PROJECT_ARCHITECTURE.md', $src);
        self::assertStringContainsString('task.site_id', $src);
    }

    public function test_move_service_does_not_require_project_site_match(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectTaskMoveService::class))->getFileName(),
        );
        self::assertStringNotContainsString('move_domain_mismatch', $src);
        self::assertStringContainsString('Never rewrite task.site_id', $src);
        self::assertStringContainsString('assertTargetHasPackingSlots', $src);
    }

    public function test_agent_policy_allows_domain_neutral_execution_projects(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentPolicy::class))->getFileName(),
        );
        self::assertStringContainsString('Domain-neutral projects (null site_id)', $src);
        self::assertStringNotContainsString('&& ! $project->isDraftPlanning()', $src);
    }

    public function test_agent_read_does_not_reject_null_project_site_against_working_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentReadService::class))->getFileName(),
        );
        self::assertStringContainsString('$projectSiteId > 0 && $projectSiteId !== $siteId', $src);
        self::assertStringNotContainsString('Project domain is required.', $src);
        self::assertStringNotContainsString('Project does not belong to site context.', $src);
    }
}
