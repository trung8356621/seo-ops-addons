<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerKeywordIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspaceItemOps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaWorkspacePersistenceService;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Keyword UI → Idea Manager intake (not Shared Planning Draft).
 */
final class IdeaManagerKeywordIntakeContractTest extends TestCase
{
    public function test_intake_service_uses_workspace_persistence_not_project_tasks(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerKeywordIntakeService::class))->getFileName()
        );
        self::assertStringContainsString('IdeaWorkspacePersistenceService', $src);
        self::assertStringContainsString('IdeaWorkspaceItemOps::makeManualSeed', $src);
        self::assertStringContainsString('function addPhrases', $src);
        self::assertStringContainsString('function addKeywords', $src);
        self::assertStringContainsString('source_site_id', $src);
        self::assertStringContainsString('source_domain', $src);
        self::assertStringNotContainsString('PlanningDraftIntakeService', $src);
        self::assertStringNotContainsString('SeoProjectTask', $src);
        self::assertStringContainsString('Does NOT write seo_project_tasks', $src);
        self::assertStringContainsString('Does NOT Generate', $src);
        self::assertStringNotContainsString('->table(', $src);
        self::assertStringNotContainsString('SeoProject::', $src);
    }

    public function test_drawer_keyword_mode_label_and_destination(): void
    {
        self::assertSame(
            'seo-content-ai::filament.article_list.add_to_idea_manager',
            AssignToContentProjectContract::KEYWORD_LABEL_KEY,
        );
        self::assertNotSame(
            AssignToContentProjectContract::LABEL_KEY,
            AssignToContentProjectContract::KEYWORD_LABEL_KEY,
        );
        self::assertStringContainsString('MODE_KEYWORD', (string) file_get_contents(
            (string) (new ReflectionClass(AssignToContentProjectContract::class))->getFileName()
        ));
        self::assertStringContainsString('labelForMode', (string) file_get_contents(
            (string) (new ReflectionClass(AssignToContentProjectContract::class))->getFileName()
        ));

        $drawer = (string) file_get_contents(
            (string) (new ReflectionClass(AssignToContentProjectDrawer::class))->getFileName()
        );
        self::assertStringContainsString('labelForMode', $drawer);
        self::assertStringContainsString("'destination' => 'idea_manager'", $drawer);

        $blade = (string) file_get_contents(
            dirname((string) (new ReflectionClass(AssignToContentProjectDrawer::class))->getFileName(), 3)
            .'/resources/views/livewire/assign-to-content-project-drawer.blade.php'
        );
        self::assertStringContainsString('labelForMode($mode)', $blade);
        self::assertStringContainsString('add_to_idea_manager_description', $blade);
    }

    public function test_other_planning_draft_callers_still_use_add_keywords(): void
    {
        $api = (string) file_get_contents(
            (string) (new ReflectionClass(ServiceApiDraftIntakeService::class))->getFileName()
        );
        self::assertStringContainsString('->addKeywords(', $api);
        self::assertStringContainsString('PlanningDraftIntakeService', $api);
        self::assertStringNotContainsString('IdeaManagerKeywordIntakeService', $api);

        $draft = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftIntakeService::class))->getFileName()
        );
        self::assertStringContainsString('function addKeywords', $draft);
    }

    public function test_manual_seed_dedupe_rules_still_apply(): void
    {
        $first = IdeaWorkspaceItemOps::makeManualSeed('foam PE', []);
        self::assertNotNull($first['item']);
        self::assertFalse($first['duplicate']);

        $dup = IdeaWorkspaceItemOps::makeManualSeed('foam PE', [$first['item']]);
        self::assertTrue($dup['duplicate']);
        self::assertNull($dup['item']);
    }

    public function test_lang_keys_present(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');
        foreach ([
            'add_to_idea_manager',
            'add_to_idea_manager_completed',
            'already_in_idea_manager',
            'add_to_idea_manager_description',
        ] as $key) {
            self::assertStringContainsString("'{$key}'", $en);
            self::assertStringContainsString("'{$key}'", $vi);
        }
    }

    public function test_persistence_service_unchanged_for_revision_contract(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaWorkspacePersistenceService::class))->getFileName()
        );
        self::assertStringContainsString('STATUS_CONFLICT', $src);
        self::assertStringContainsString('MAX_NOTE_ITEMS', $src);
        self::assertStringContainsString('SeoContentProjectIdeaWorkspace', $src);
    }
}
