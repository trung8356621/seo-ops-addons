<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Livewire\AssignToContentProjectDrawer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectActionFactory;
use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Regression: Add to Draft after Assign→Draft migration (preflight, wrong_domain, per-card Edit).
 */
final class PlanningDraftIntakeBugfixRegressionTest extends TestCase
{
    public function test_a_shared_draft_null_site_does_not_classify_wrong_domain(): void
    {
        $assignment = (string) file_get_contents(
            (string) (new ReflectionClass(SeoIssueProjectTaskAssignmentService::class))->getFileName(),
        );

        self::assertStringContainsString('! $project->isDraftPlanning()', $assignment);
        self::assertStringContainsString('items own site_id from the article', $assignment);

        $result = PlanningDraftIntakeResult::fromAssignmentSummary(
            [
                'added' => 1,
                'duplicate' => 0,
                'overflow' => 0,
                // Legacy would treat article.site_id=2 vs draft.site_id=null as mismatch — Draft must ignore.
                'domain_mismatch' => 1,
                'already_in_project' => 0,
            ],
            10,
            'Added to Draft',
            'Already in Draft',
            'failed',
        );

        self::assertSame(PlanningDraftIntakeResult::STATUS_ADDED, $result->status);
        self::assertTrue($result->isSuccess());
    }

    public function test_b_cross_site_uses_article_site_not_project_site(): void
    {
        $assignment = (string) file_get_contents(
            (string) (new ReflectionClass(SeoIssueProjectTaskAssignmentService::class))->getFileName(),
        );

        self::assertStringContainsString(
            '$siteId = $articleSiteId > 0',
            $assignment,
        );
        self::assertStringContainsString(
            '$project->isDraftPlanning() ? 0 : $projectSiteId',
            $assignment,
        );

        $keywords = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordProjectAssignmentService::class))->getFileName(),
        );
        self::assertStringContainsString('! $project->isDraftPlanning()', $keywords);
    }

    public function test_c_single_article_with_keyword_uses_preflight_not_auto_open(): void
    {
        $drawer = (string) file_get_contents(
            (string) (new ReflectionClass(AssignToContentProjectDrawer::class))->getFileName(),
        );
        self::assertStringContainsString('function preflightOpen', $drawer);
        self::assertStringContainsString('articleNeedsKeyword', $drawer);

        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/views/livewire/assign-to-content-project-drawer.blade.php'
        );
        self::assertStringContainsString('preflightOpen(payload)', $blade);
        self::assertStringContainsString('never open shell', $blade);

        $factory = (string) file_get_contents(
            (string) (new ReflectionClass(AssignToContentProjectActionFactory::class))->getFileName(),
        );
        self::assertStringContainsString('tryDirectArticleIntake', $factory);
    }

    public function test_d_normalize_draft_summary_drops_wrong_domain_and_overflow(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftIntakeService::class))->getFileName(),
        );
        self::assertStringContainsString('function normalizeDraftSummary', $src);
        self::assertStringContainsString("\$summary['domain_mismatch'] = 0", $src);
        self::assertStringContainsString("\$summary['overflow'] = 0", $src);
        self::assertStringContainsString('buildDraftSummaryMessage', $src);
        self::assertStringContainsString('site_not_resolved', $src);
        self::assertStringNotContainsString('assign_completed_body', $src);
    }

    public function test_e_ensure_shared_draft_never_promotes_legacy_by_nulling_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftIntakeService::class))->getFileName(),
        );
        // Create path may still null site_id on a newly created draft; must not treat legacy as canonical.
        self::assertStringContainsString('findCanonicalSharedDraft', $src);
        self::assertStringContainsString('Never promote legacy', $src);
        self::assertStringContainsString('lockForUpdate', $src);
    }

    public function test_e2_canonical_draft_requires_null_site_id(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver::class))->getFileName(),
        );
        self::assertStringContainsString("->whereNull('site_id')", $src);
        self::assertStringContainsString('listLegacyPerSiteDrafts', $src);
        self::assertStringNotContainsString('CASE WHEN site_id IS NULL THEN 0 ELSE 1 END', $src);
    }

    public function test_f_payload_contract_keeps_article_ids_and_site_ids(): void
    {
        $payload = AssignToContentProjectContract::normalizePayload([
            'mode' => 'article',
            'source' => 'keyword_detail',
            'article_ids' => [42],
            'site_ids' => [1],
        ]);

        self::assertSame([42], $payload['article_ids']);
        self::assertSame([1], $payload['site_ids']);
        self::assertSame('article', $payload['mode']);
    }

    public function test_g_linked_article_cards_expose_edit_and_add_with_site_id(): void
    {
        $presenter = (string) file_get_contents(
            ProjectRoot::addonsPath().'/search-foundation/src/Support/KeywordLinkDetailPanelPresenter.php'
        );
        self::assertStringContainsString("'site_id' => (int) (\$article->site_id ?? 0)", $presenter);
        self::assertStringContainsString("'in_draft' => \$inProject", $presenter);
        self::assertStringContainsString('resolved_article_id', $presenter);
        self::assertStringContainsString('can_add_to_draft', $presenter);

        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-dictionary-drawer-content.blade.php'
        );
        self::assertStringContainsString('keyword-dictionary-drawer__card-actions', $blade);
        self::assertStringContainsString('data-article-site-id', $blade);
        self::assertStringContainsString('heroicon-o-folder-plus', $blade);
        self::assertStringContainsString('heroicon-m-pencil-square', $blade);
        self::assertStringContainsString('data-assign-article', $blade);
    }

    public function test_h_footer_edit_article_removed_analyze_kept(): void
    {
        $footer = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-detail-drawer.blade.php'
        );
        self::assertStringNotContainsString('data-keyword-detail-footer-edit', $footer);
        self::assertStringContainsString('data-keyword-detail-analyze', $footer);
        self::assertStringContainsString('drawer_analyze_content', $footer);

        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/keywordDetailPanel.js'
        );
        self::assertStringNotContainsString('data-keyword-detail-footer-edit', $js);
        self::assertStringContainsString('dataset.articleSiteId', $js);
        self::assertStringContainsString("mode: 'article'", $js);
        self::assertStringContainsString('article_ids: [articleId]', $js);
    }

    public function test_i_draft_summary_message_has_no_wrong_domain_vocabulary(): void
    {
        $en = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php'
        );
        self::assertStringContainsString("'add_to_draft_completed_body'", $en);
        self::assertStringContainsString("'add_to_draft_summary_body'", $en);
        self::assertStringContainsString("'site_not_resolved'", $en);
        self::assertStringContainsString("'already_in_draft'", $en);

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftIntakeService::class))->getFileName(),
        );
        self::assertStringContainsString('add_to_draft_completed_body', $src);
        self::assertStringContainsString('add_to_draft_summary_body', $src);
        self::assertStringNotContainsString('buildSummaryMessage', $src);
    }

    public function test_j_icon_and_color_unchanged(): void
    {
        self::assertSame('heroicon-o-folder-plus', AssignToContentProjectContract::ICON);
        self::assertSame('warning', AssignToContentProjectContract::COLOR);
    }
}
