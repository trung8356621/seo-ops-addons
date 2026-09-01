<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftExecutionGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectActionDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectGenerationGate;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditCheckIndexUrl;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditExistingContentSuggestionService;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use PHPUnit\Framework\TestCase;

final class ContentProjectDraftPlanningTest extends TestCase
{
    public function test_draft_status_constant_and_is_draft_planning(): void
    {
        $draft = new SeoProject;
        $draft->setRawAttributes([
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'archived_at' => null,
        ], true);

        self::assertTrue($draft->isDraftPlanning());
        self::assertSame(PHP_INT_MAX, $draft->maxTasksAllowed());
        self::assertTrue($draft->canRegisterMoreTasks());
        self::assertTrue($draft->isExecutionMonthOpen());
        self::assertSame(PHP_INT_MAX, $draft->remainingTaskCapacity());
    }

    public function test_draft_and_active_capacity_are_unlimited(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Models/SeoProject.php',
        );

        self::assertStringContainsString('isDraftPlanning()', $src);
        self::assertStringContainsString('return PHP_INT_MAX', $src);
        self::assertMatchesRegularExpression(
            '/function maxTasksAllowed\(\): int\s*\{[^}]*return PHP_INT_MAX;/s',
            $src,
        );
    }

    public function test_active_project_is_not_draft_planning(): void
    {
        $active = new SeoProject;
        $active->setRawAttributes([
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'archived_at' => null,
        ], true);

        self::assertFalse($active->isDraftPlanning());
        self::assertFalse(ContentProjectDraftExecutionGuard::blocks($active));
    }

    public function test_draft_execution_guard_rejects(): void
    {
        $draft = new SeoProject;
        $draft->setRawAttributes([
            'id' => 99,
            'status' => SeoProject::STATUS_DRAFT,
            'kind' => SeoProject::KIND_MONTHLY,
            'archived_at' => null,
        ], true);

        $result = ContentProjectDraftExecutionGuard::rejectIfDraft($draft, 99);
        self::assertNotNull($result);
        self::assertFalse($result->success);
        self::assertSame(ContentProjectActionCodes::PROJECT_DRAFT_NOT_EXECUTABLE, $result->code);
    }

    public function test_generation_gate_resolve_disables_draft(): void
    {
        $decision = ContentProjectProjectGenerationGate::resolve(
            [1, 2, 3],
            conflictActive: false,
            conflictReason: ContentProjectProjectActionDecision::REASON_BULK_ACTIVE,
            archived: false,
            draftPlanning: true,
        );

        self::assertFalse($decision->enabled);
        self::assertSame(ContentProjectProjectActionDecision::REASON_DRAFT_PLANNING, $decision->reasonCode);
        self::assertSame([], $decision->eligibleTaskIds);
    }

    public function test_default_draft_name_is_shared_not_per_domain(): void
    {
        $name = SeoProject::defaultDraftName('example.com');
        self::assertSame('Planning Draft', $name);
        self::assertStringNotContainsString('example.com', $name);
        self::assertStringNotContainsString('2026', $name);
        self::assertDoesNotMatchRegularExpression('/\d{1,2}\/\d{4}/', $name);
        self::assertSame(SeoProject::defaultDraftName(), SeoProject::defaultDraftName('other.com'));
    }

    public function test_check_index_url_encoding(): void
    {
        $url = SeoAuditCheckIndexUrl::forCanonicalUrl('https://example.com/my-post/');
        self::assertSame(
            'https://www.google.com/search?q='.rawurlencode('site:https://example.com/my-post/'),
            $url,
        );
        self::assertNull(SeoAuditCheckIndexUrl::forCanonicalUrl(''));
        self::assertNull(SeoAuditCheckIndexUrl::forCanonicalUrl(null));
    }

    public function test_suggest_action_prefers_improve_for_targeted_issues(): void
    {
        $service = (new \ReflectionClass(SeoAuditExistingContentSuggestionService::class))
            ->newInstanceWithoutConstructor();

        $action = $service->suggestAction(
            72,
            [SeoScoringRulesRegistry::KEY_FAQ_MISSING, SeoScoringRulesRegistry::KEY_KEYWORD_MISSING_IN_META],
            false,
        );

        self::assertSame(SeoAuditExistingContentSuggestionService::ACTION_IMPROVE, $action);
    }

    public function test_suggest_action_rewrite_for_very_low_score(): void
    {
        $service = (new \ReflectionClass(SeoAuditExistingContentSuggestionService::class))
            ->newInstanceWithoutConstructor();

        $action = $service->suggestAction(
            31,
            [
                SeoScoringRulesRegistry::KEY_CONTENT_LENGTH_LOW,
                SeoScoringRulesRegistry::KEY_FAQ_MISSING,
                SeoScoringRulesRegistry::KEY_H2_MISSING,
                SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_MISSING,
            ],
            true,
        );

        self::assertSame(SeoAuditExistingContentSuggestionService::ACTION_REWRITE, $action);
    }

    public function test_recommendation_summary_is_concise(): void
    {
        $service = (new \ReflectionClass(SeoAuditExistingContentSuggestionService::class))
            ->newInstanceWithoutConstructor();

        $summary = $service->recommendationSummary(
            SeoAuditExistingContentSuggestionService::ACTION_IMPROVE,
            ['Missing FAQ', 'Missing meta description', 'Heading structure', 'Extra'],
        );

        self::assertStringStartsWith('Improve:', $summary);
        self::assertStringContainsString('Missing FAQ', $summary);
        self::assertStringNotContainsString('Extra', $summary);
    }

    public function test_command_bus_registers_suggestion_commands(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );

        self::assertStringContainsString('AddSeoAuditSuggestionsCommand::class', $src);
        self::assertStringContainsString('FillSeoAuditSuggestionsCommand::class', $src);
        self::assertStringContainsString('DismissSeoAuditSuggestionsCommand::class', $src);
        self::assertStringContainsString('RestoreSeoAuditSuggestionsCommand::class', $src);
        self::assertStringContainsString(
            'use Omnichannel\\Addons\\ContentProjects\\Services\\ContentProject\\Application\\Commands\\CreateContentProjectCommand;',
            $src,
        );
        self::assertStringContainsString(
            'use Omnichannel\\Addons\\ContentProjects\\Services\\ContentProject\\Application\\Handlers\\CreateContentProjectHandler;',
            $src,
        );
    }

    public function test_view_and_blade_wire_suggestions_tab(): void
    {
        $page = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Pages/ViewSeoProject.php',
        );
        $blade = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php',
        );

        self::assertStringContainsString('InteractsWithSeoAuditSuggestions', $page);
        self::assertStringContainsString("setWorkspaceTab('suggestions')", $blade);
        self::assertStringContainsString('content-project-seo-audit-planner', $blade);
        self::assertStringContainsString('isDraftPlanning()', $blade);
    }

    public function test_planner_uses_assignment_service_not_direct_create(): void
    {
        $planner = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/SeoAudit/SeoAuditSuggestionPlannerService.php',
        );

        self::assertStringContainsString('SeoIssueProjectTaskAssignmentService', $planner);
        self::assertStringContainsString('isDraftPlanning()', $planner);
        self::assertStringNotContainsString('SeoProjectTask::create(', $planner);
    }

    public function test_decision_and_origin_migrations_exist(): void
    {
        $decisions = dirname(__DIR__, 2).'/database/migrations/2026_08_24_100000_create_seo_content_project_suggestion_decisions_table.php';
        $origins = dirname(__DIR__, 2).'/database/migrations/2026_08_24_100100_create_seo_content_project_item_origins_table.php';
        self::assertFileExists($decisions);
        self::assertFileExists($origins);
        self::assertStringContainsString('scp_suggestion_decisions_project_source_unique', (string) file_get_contents($decisions));
        self::assertStringContainsString('seo_content_project_item_origins', (string) file_get_contents($origins));
    }
}
