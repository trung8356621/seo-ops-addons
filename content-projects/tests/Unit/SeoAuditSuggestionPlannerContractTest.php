<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectSuggestionDecision;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionDecisionService;
use PHPUnit\Framework\TestCase;

final class SeoAuditSuggestionPlannerContractTest extends TestCase
{
    public function test_article_source_key_is_stable(): void
    {
        self::assertSame('article:123', SeoContentProjectSuggestionDecision::articleSourceKey(123));
    }

    public function test_decision_service_methods_exist(): void
    {
        $ref = new \ReflectionClass(SeoAuditSuggestionDecisionService::class);
        self::assertTrue($ref->hasMethod('dismissArticles'));
        self::assertTrue($ref->hasMethod('restoreArticles'));
        self::assertTrue($ref->hasMethod('dismissedArticleIds'));
        self::assertTrue($ref->hasMethod('markAccepted'));
    }

    public function test_dismiss_handlers_are_draft_only(): void
    {
        $dismiss = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/DismissSeoAuditSuggestionsHandler.php',
        );
        $restore = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/RestoreSeoAuditSuggestionsHandler.php',
        );
        $add = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/AddSeoAuditSuggestionsHandler.php',
        );

        self::assertStringContainsString('isDraftPlanning()', $dismiss);
        self::assertStringContainsString('isDraftPlanning()', $restore);
        self::assertStringContainsString('isDraftPlanning()', $add);
        self::assertStringContainsString('SUGGESTIONS_PLANNING_DRAFT_ONLY', $add);
    }

    public function test_project_scoped_unique_is_project_plus_source(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_24_100000_create_seo_content_project_suggestion_decisions_table.php',
        );

        self::assertStringContainsString("['project_id', 'source_type', 'source_key']", $src);
    }

    public function test_suggestion_service_scopes_articles_by_explicit_site(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/SeoAudit/SeoAuditExistingContentSuggestionService.php',
        );

        self::assertStringContainsString('function paginate(SeoProject $project, Site $site', $src);
        self::assertStringContainsString("where('articles.site_id', \$siteId)", $src);
        self::assertStringContainsString('siteId <= 0', $src);
    }

    public function test_planner_scopes_articles_by_working_site(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/SeoAudit/SeoAuditSuggestionPlannerService.php',
        );

        self::assertStringContainsString('Site $site', $src);
        self::assertStringContainsString('addToDraftProject(', $src);
        self::assertStringContainsString("->where('site_id', \$siteId)", $src);
        self::assertStringContainsString('notContentArchived()', $src);
    }

    public function test_generate_handler_wires_draft_guard(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/GenerateProjectItemsHandler.php',
        );

        self::assertStringContainsString('ContentProjectDraftExecutionGuard', $src);
        self::assertStringContainsString('rejectIfDraft', $src);
    }

    public function test_publish_and_schedule_handlers_wire_draft_guard(): void
    {
        foreach ([
            'PublishProjectItemsNowHandler.php',
            'AutoScheduleProjectItemsHandler.php',
            'SendToPublishingQueueHandler.php',
            'RerunProjectItemsHandler.php',
        ] as $file) {
            $src = (string) file_get_contents(
                dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/'.$file,
            );
            self::assertStringContainsString(
                'rejectIfDraft',
                $src,
                $file.' must reject draft execution',
            );
        }
    }

    public function test_non_draft_passes_guard(): void
    {
        $project = new SeoProject;
        $project->setRawAttributes([
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'archived_at' => null,
        ], true);

        self::assertNull(ContentProjectDraftExecutionGuard::rejectIfDraft($project, 1));
    }
}
