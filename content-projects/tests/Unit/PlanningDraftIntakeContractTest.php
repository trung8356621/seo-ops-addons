<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Add-to-Draft / Shared Planning Draft intake contracts.
 */
final class PlanningDraftIntakeContractTest extends TestCase
{
    public function test_intake_service_and_result_exist(): void
    {
        self::assertTrue(class_exists(PlanningDraftIntakeService::class));
        self::assertTrue(class_exists(PlanningDraftIntakeResult::class));
        self::assertTrue(class_exists(PlanningDraftResolver::class));
        self::assertSame('added', PlanningDraftIntakeResult::STATUS_ADDED);
        self::assertSame('already_in_draft', PlanningDraftIntakeResult::STATUS_ALREADY_IN_DRAFT);
        self::assertSame('failed', PlanningDraftIntakeResult::STATUS_FAILED);
        self::assertSame('missing_keyword', PlanningDraftIntakeResult::STATUS_MISSING_KEYWORD);
        self::assertSame('site_not_resolved', PlanningDraftIntakeResult::STATUS_SITE_NOT_RESOLVED);
        self::assertSame('draft_not_resolved', PlanningDraftIntakeResult::STATUS_DRAFT_NOT_RESOLVED);
    }

    public function test_intake_service_api_surface(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftIntakeService::class))->getFileName(),
        );

        self::assertStringContainsString('function ensureSharedDraft', $src);
        self::assertStringContainsString('findCanonicalSharedDraft', $src);
        self::assertStringContainsString('function addArticles', $src);
        self::assertStringContainsString('function addKeywords', $src);
        self::assertStringContainsString('function addVocabularyPhrases', $src);
        self::assertStringContainsString('function addPendingLink', $src);
        self::assertStringContainsString('articleNeedsKeyword', $src);
        self::assertStringContainsString('KeywordFocusAttach::syncMainKeyword', $src);
        self::assertStringContainsString('STATUS_DRAFT', $src);
        self::assertStringContainsString('confirmPersistedArticleAdds', $src);
        self::assertStringContainsString('logIntakeOutcome', $src);
        self::assertStringContainsString('STATUS_DRAFT_NOT_RESOLVED', $src);
        self::assertStringContainsString('Never promote legacy', $src);
        self::assertStringContainsString('lockForUpdate', $src);
        self::assertStringContainsString('result_status', $src);
        self::assertStringContainsString('source_site_id', $src);
        // Never trust global site for item.site_id — article/keyword site only.
        self::assertStringNotContainsString('globalSiteId()', $src);
        self::assertStringNotContainsString('globalContentProjectId', $src);
    }

    public function test_ensure_shared_draft_never_looks_up_by_site_id(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftIntakeService::class))->getFileName(),
        );
        // ensureSharedDraft body must not call findPlanningDraftForSite.
        $start = strpos($src, 'function ensureSharedDraft');
        self::assertNotFalse($start);
        $chunk = substr($src, $start, 1800);
        self::assertStringNotContainsString('findPlanningDraftForSite', $chunk);
        self::assertStringContainsString('findCanonicalSharedDraft', $chunk);
    }

    public function test_direct_intake_does_not_emit_success_on_failure(): void
    {
        $factory = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectActionFactory::class))->getFileName(),
        );
        self::assertStringContainsString('if (! $result->isSuccess())', $factory);
        self::assertStringContainsString('SUCCESS_EVENT', $factory);
    }

    public function test_already_in_draft_result_mapping(): void
    {
        $result = PlanningDraftIntakeResult::fromAssignmentSummary(
            [
                'added' => 0,
                'duplicate' => 1,
                'overflow' => 0,
                'domain_mismatch' => 0,
                'already_in_project' => 0,
            ],
            99,
            'Added to Draft',
            'Already in Draft',
            'failed',
            [1],
        );

        self::assertTrue($result->isAlreadyInDraft());
        self::assertTrue($result->isSuccess());
        self::assertSame(99, $result->draftProjectId);
        self::assertSame('Already in Draft', $result->message);
    }

    public function test_shared_draft_assignment_uses_article_site_id(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoIssueProjectTaskAssignmentService::class))->getFileName(),
        );

        self::assertStringContainsString('isDraftPlanning()', $src);
        self::assertStringContainsString(
            '$siteId = $articleSiteId > 0',
            $src,
        );
        self::assertStringContainsString(
            '$project->isDraftPlanning() ? 0 : $projectSiteId',
            $src,
        );
        // Legacy hard reject article.site_id !== project.site_id must not apply to Draft.
        self::assertStringContainsString('! $project->isDraftPlanning()', $src);
    }

    public function test_label_is_add_to_draft_icon_unchanged(): void
    {
        self::assertSame('heroicon-o-folder-plus', AssignToContentProjectContract::ICON);
        self::assertSame(
            'seo-content-ai::filament.article_list.add_to_draft',
            AssignToContentProjectContract::LABEL_KEY,
        );

        $en = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php'
        );
        self::assertStringContainsString("'add_to_draft' => 'Add to Draft'", $en);
        self::assertStringContainsString("'already_in_draft' => 'Already in Draft'", $en);
    }

    public function test_entry_points_share_intake_service(): void
    {
        $drawer = (string) file_get_contents(
            (string) (new ReflectionClass(AssignToContentProjectDrawer::class))->getFileName(),
        );
        $editArticle = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php'
        );

        self::assertStringContainsString('PlanningDraftIntakeService', $drawer);
        self::assertStringContainsString('PlanningDraftIntakeService', $editArticle);
        self::assertStringContainsString('addVocabularyItemsToDraft', $editArticle);
    }
}
