<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Keyword\AssignKeywordToProjectAction;
use Omnichannel\Addons\Agent\Automation\Actions\Seo\CreateProjectTaskFromSeoIssueAction;
use Omnichannel\Addons\Agent\Automation\Migration\AssignmentCallerBridge;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression: assign actions must use domain services, not Filament Resources.
 */
final class AutomationDomainAssignmentServiceTest extends TestCase
{
    public function test_seo_issue_assignment_service_has_no_filament_dependency(): void
    {
        $path = (new ReflectionClass(SeoIssueProjectTaskAssignmentService::class))->getFileName();
        self::assertNotFalse($path);
        $contents = (string) file_get_contents($path);

        self::assertStringNotContainsString('Filament\\', $contents);
        self::assertStringNotContainsString('ArticleResource', $contents);
        self::assertStringNotContainsString('Notification::', $contents);
    }

    public function test_keyword_assignment_service_has_no_filament_dependency(): void
    {
        $path = (new ReflectionClass(KeywordProjectAssignmentService::class))->getFileName();
        self::assertNotFalse($path);
        $contents = (string) file_get_contents($path);

        self::assertStringNotContainsString('Filament\\', $contents);
        self::assertStringNotContainsString('KeywordResource', $contents);
        self::assertStringNotContainsString('Notification::', $contents);
    }

    public function test_assign_actions_typehint_domain_services(): void
    {
        $seoCtor = (new ReflectionClass(CreateProjectTaskFromSeoIssueAction::class))->getConstructor();
        self::assertNotNull($seoCtor);
        $seoParams = $seoCtor->getParameters();
        self::assertCount(1, $seoParams);
        self::assertSame(SeoIssueProjectTaskAssignmentService::class, $seoParams[0]->getType()?->getName());

        $kwCtor = (new ReflectionClass(AssignKeywordToProjectAction::class))->getConstructor();
        self::assertNotNull($kwCtor);
        $kwParams = $kwCtor->getParameters();
        self::assertCount(1, $kwParams);
        self::assertSame(KeywordProjectAssignmentService::class, $kwParams[0]->getType()?->getName());
    }

    public function test_seo_issue_assignment_preserves_manual_keyword_override(): void
    {
        $resource = (string) file_get_contents((new ReflectionClass(ArticleResource::class))->getFileName());
        $bridge = (string) file_get_contents((new ReflectionClass(AssignmentCallerBridge::class))->getFileName());
        $action = (string) file_get_contents((new ReflectionClass(CreateProjectTaskFromSeoIssueAction::class))->getFileName());
        $service = (string) file_get_contents((new ReflectionClass(SeoIssueProjectTaskAssignmentService::class))->getFileName());

        self::assertStringContainsString("\$data['focus_keyword']", $resource);
        self::assertStringContainsString('$keywordOverride', $bridge);
        self::assertStringContainsString("'keyword' => \$keywordOverride", $bridge);
        self::assertStringContainsString("'keyword' => \$input['keyword'] ?? null", $action);
        self::assertStringContainsString('$normalizedKeywordOverride', $service);
        self::assertStringContainsString('$normalizedKeywordOverride !== \'\'', $service);
    }
}
