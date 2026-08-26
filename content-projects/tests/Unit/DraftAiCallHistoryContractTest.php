<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Support\PromptAiCallErrorNormalizer;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectDraftAiHistory;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectPlannerRunDetail;
use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftAiCalls;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\ContentProjectDraftAiCallHistoryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\ProjectRoot;

final class DraftAiCallHistoryContractTest extends TestCase
{
    public function test_service_queries_planner_runs_with_prompt_result_only(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(ContentProjectDraftAiCallHistoryService::class))->getFileName());

        self::assertStringContainsString('SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT', $src);
        self::assertStringContainsString('prompt_result_id', $src);
        self::assertStringContainsString('whereNotNull(\'prompt_result_id\')', $src);
        self::assertStringContainsString('unique()', $src);
        self::assertStringContainsString('PromptResult::query()', $src);
        self::assertStringContainsString('with(\'prompt\')', $src);
        self::assertStringNotContainsString('SOURCE_SEO_AUDIT', $src);

        $planner = (string) file_get_contents((new ReflectionClass(
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService::class,
        ))->getFileName());
        self::assertStringContainsString("\$e->context['prompt_result_id']", $planner);
        self::assertStringContainsString('extractPromptResultId($e)', $planner);
    }

    public function test_error_normalizer_rejects_boolean_false(): void
    {
        self::assertNull(PromptAiCallErrorNormalizer::display(false));
        self::assertSame('AI call failed.', PromptAiCallErrorNormalizer::display(true));
        self::assertSame('AI call failed.', PromptAiCallErrorNormalizer::display('false'));
        self::assertSame('Providers exhausted', PromptAiCallErrorNormalizer::display('Providers exhausted'));
        self::assertNull(PromptAiCallErrorNormalizer::display(null));
    }

    public function test_dedicated_ai_history_page_reuses_shared_panel(): void
    {
        $page = (string) file_get_contents((new ReflectionClass(ContentProjectDraftAiHistory::class))->getFileName());
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/content-project-draft-ai-history.blade.php',
        );
        $panel = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/prompt-ai-calls-panel.blade.php',
        );

        self::assertStringContainsString('InteractsWithDraftAiCalls', $page);
        self::assertStringContainsString("slug = 'content-projects/ai-history'", $page);
        self::assertStringContainsString('shouldRegisterNavigation = false', $page);
        self::assertStringContainsString('prompt-ai-calls-panel', $blade);
        self::assertStringContainsString('loadDraftRawAiCallDetail', $blade);
        self::assertStringContainsString('seo-run-history-page', $panel);
        self::assertStringNotContainsString('applyOutline', $panel);
        self::assertTrue(trait_exists(InteractsWithDraftAiCalls::class));
    }

    public function test_content_planning_no_longer_embeds_ai_calls_panel(): void
    {
        $planner = (string) file_get_contents((new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName());
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/content-project-seo-audit-planner.blade.php',
        );

        self::assertStringNotContainsString('InteractsWithDraftAiCalls', $planner);
        self::assertStringNotContainsString('setDraftSectionTab', $blade);
        self::assertStringNotContainsString('prompt-ai-calls-panel', $blade);
        self::assertStringNotContainsString('data-content-planning-action="ai-history"', $blade);
        self::assertStringContainsString('content-project-draft-items', $blade);

        $card = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-new-content-card.blade.php',
        );
        self::assertStringContainsString('draft_ai_history_link', $card);
        self::assertStringContainsString('data-new-content-ai-history="1"', $card);
        self::assertStringContainsString('target="_blank"', $card);
    }

    public function test_planner_run_detail_page_and_view_results_link(): void
    {
        $page = (string) file_get_contents((new ReflectionClass(ContentProjectPlannerRunDetail::class))->getFileName());
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/pages/content-project-planner-run-detail.blade.php',
        );
        $card = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-new-content-card.blade.php',
        );

        self::assertStringContainsString("slug = 'content-projects/planner-runs'", $page);
        self::assertStringContainsString('findForProject', $page);
        self::assertStringContainsString('data-planner-run-detail', $blade);
        self::assertStringContainsString('data-planner-run-candidates', $blade);
        self::assertStringContainsString('planner_run_summary_line', $blade);
        self::assertStringNotContainsString('planner_view_results_link', $card);
        self::assertStringNotContainsString('data-planner-view-results', $card);
        self::assertStringNotContainsString('viewNewContentHistoryResults', $card);
        self::assertStringNotContainsString('resultsOpen', $card);
        self::assertStringNotContainsString('historyOpen', $card);

        // Stale Livewire snapshots still send these keys; undeclared → PublicPropertyNotFoundException
        // on unrelated ViewSeoProject actions (send to publishing queue, etc.).
        $trait = new ReflectionClass(InteractsWithNewContentSuggestions::class);
        self::assertTrue($trait->hasProperty('newContentViewRunId'));
        self::assertTrue($trait->hasProperty('newContentViewResults'));
        self::assertTrue((new ReflectionProperty(InteractsWithNewContentSuggestions::class, 'newContentViewRunId'))->isPublic());
        self::assertTrue((new ReflectionProperty(InteractsWithNewContentSuggestions::class, 'newContentViewResults'))->isPublic());
    }

    public function test_article_ai_calls_page_still_present(): void
    {
        $blade = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/view-article-prompts.blade.php',
        );
        self::assertStringContainsString('getAiCallGroups', $blade);
        self::assertStringContainsString('tab_ai_calls', $blade);
        self::assertStringContainsString('ARTICLE #', $blade);
        self::assertStringContainsString('applyOutline', $blade);
    }

    public function test_planner_cards_retire_inline_history_keep_ai_history_link(): void
    {
        $improve = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-draft-planner.blade.php',
        );
        $card = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/components/content-project-new-content-card.blade.php',
        );

        self::assertStringNotContainsString('planner_history', $improve);
        self::assertStringNotContainsString('historyOpen', $improve);
        self::assertStringNotContainsString('planner_load_filters', $improve);
        self::assertStringNotContainsString('planner_run_again', $improve);
        self::assertStringContainsString('content_planning_recent_filters', $improve);

        self::assertStringNotContainsString('planner_history', $card);
        self::assertStringNotContainsString('historyOpen', $card);
        self::assertStringNotContainsString('content_planning_recent_runs', $card);
        self::assertStringNotContainsString('planner_load_options', $card);
        self::assertStringNotContainsString('planner_generate_again', $card);
        self::assertStringNotContainsString('planner_view_results', $card);
        self::assertStringContainsString('planner_planning_context', $card);
        self::assertStringContainsString('draft_ai_history_link', $card);
        self::assertStringNotContainsString('planner_options', $card);
        self::assertStringNotContainsString('planner_save_options', $card);
        self::assertStringNotContainsString('saveNewContentOptions', (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithNewContentSuggestions::class))->getFileName(),
        ));
        self::assertStringNotContainsString('setDraftSectionTab(\'ai_calls\')', $card);
    }
}
