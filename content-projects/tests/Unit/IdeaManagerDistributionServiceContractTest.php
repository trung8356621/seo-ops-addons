<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\DiscoverNewTopicsDuplicateFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerDistributionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceItemOps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Distribution contract: destination Idea workspace merge — no Generate/Publish.
 */
final class IdeaManagerDistributionServiceContractTest extends TestCase
{
    public function test_persistence_contract_is_destination_idea_workspace(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName()
        );
        self::assertStringContainsString('seo_content_project_idea_workspaces', $src);
        self::assertStringContainsString('IdeaWorkspacePersistenceService', $src);
        self::assertStringContainsString('IdeaWorkspaceItemOps::findDuplicate', $src);
        self::assertStringContainsString('DiscoverNewTopicsDuplicateFilter', $src);
        self::assertStringContainsString('AuditNoteDnaNormalizer', $src);
        self::assertStringContainsString('STATUS_ALREADY', $src);
        self::assertStringContainsString('NEVER block Confirm', $src);
        self::assertStringContainsString('Does NOT Generate Ideas', $src);
        self::assertStringContainsString('TopicGroupRetrievalClient', $src);
        self::assertStringNotContainsString('NewContentSuggestionPlannerService', $src);
        self::assertStringNotContainsString('forceFill([\'planning_month\'', $src);
        self::assertStringNotContainsString('openPublish', $src);
        self::assertDoesNotMatchRegularExpression('/->publish\s*\(/', $src);
    }

    public function test_find_duplicate_canonical_rules_block_same_name_on_destination(): void
    {
        $existing = [
            AuditNoteDnaNormalizer::normalizeNoteItem([
                'source_type' => 'cluster',
                'cluster_ref' => 'cluster:abc',
                'cluster_name_snapshot' => 'Backpack preschool',
                'target_dna_count' => 5,
                'dna' => [],
            ]),
        ];
        self::assertNotNull($existing[0]);
        $dupByName = IdeaWorkspaceItemOps::findDuplicate($existing, 'cluster:other', 'backpack preschool');
        $dupByRef = IdeaWorkspaceItemOps::findDuplicate($existing, 'cluster:abc', 'Something else');
        $fresh = IdeaWorkspaceItemOps::findDuplicate($existing, 'cluster:new', 'Canvas bag');
        self::assertNotNull($dupByName);
        self::assertNotNull($dupByRef);
        self::assertNull($fresh);
    }

    public function test_service_constructor_dependencies(): void
    {
        $ref = new ReflectionClass(IdeaManagerDistributionService::class);
        $ctor = $ref->getConstructor();
        self::assertNotNull($ctor);
        $params = $ctor->getParameters();
        self::assertGreaterThanOrEqual(2, count($params));
        self::assertSame(IdeaWorkspacePersistenceService::class, $params[0]->getType()?->getName());
        self::assertSame(DiscoverNewTopicsDuplicateFilter::class, $params[1]->getType()?->getName());
    }

    public function test_generate_planner_not_wired_from_idea_manager_page(): void
    {
        $page = dirname(__DIR__, 2).'/src/Filament/Pages/ContentProjectIdeaManager.php';
        $src = (string) file_get_contents($page);
        self::assertStringContainsString('confirmDistribution', $src);
        self::assertStringContainsString('IdeaManagerDistributionService', $src);
        self::assertStringNotContainsString(NewContentSuggestionPlannerService::class, $src);
        self::assertStringNotContainsString('generateIdeas', $src);
        self::assertStringNotContainsString('openPublishFromPlanner', $src);
    }

    public function test_phase1_audit_notes_component_untouched_by_distribution(): void
    {
        $blade = \Tests\Support\LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        self::assertStringContainsString('seoOps:content-planner:audit-notes:v2:site:', $blade);
        self::assertStringNotContainsString('IdeaManagerDistributionService', $blade);
        self::assertStringNotContainsString('confirmDistribution', $blade);
    }
}
