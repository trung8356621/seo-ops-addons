<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftSplit;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithIdeaCandidates;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateQueryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use Omnichannel\Addons\ContentProjects\Services\WriterMonthlyCapacityGate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

/**
 * UI contract: Project Planner mockup consumes closed backend SSOT (v0.7.8.2).
 * Presentation only — no client-side capacity / identity / ownership logic.
 */
final class ContentPlanningMockupUiContractTest extends TestCase
{
    public function test_available_ideas_render_provenance_not_text_dedupe(): void
    {
        $picker = LegacyAddonPath::read('resources/views/components/content-project-idea-candidate-picker.blade.php');
        $query = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateQueryService::class))->getFileName(),
        );
        $concern = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithIdeaCandidates::class))->getFileName(),
        );

        self::assertStringContainsString('data-idea-provenance="1"', $picker);
        self::assertStringContainsString('data-idea-source="{{ $sourceKey }}"', $picker);
        self::assertStringContainsString('cp-idea-card', $picker);
        self::assertStringContainsString('source_label', $picker);
        self::assertStringNotContainsString('str_contains($row[\'phrase\']', $picker);
        self::assertStringNotContainsString('similar_text', $picker);
        self::assertStringContainsString('excludeConsumedVocabularyCandidates', $query);
        self::assertStringNotContainsString('LOWER(TRIM(phrase)) NOT IN', $query);
        self::assertStringContainsString('addIdeaCandidatesAsCreate', $concern);
        self::assertStringNotContainsString('openIdeaRewritePicker', $picker);
        self::assertStringNotContainsString('data-idea-action="rewrite"', $picker);
        self::assertStringNotContainsString('data-idea-action="improve"', $picker);
    }

    public function test_ideas_panel_header_matches_mockup_create_head(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $css = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');
        $en = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php');
        $vi = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php');

        self::assertStringContainsString('cp-plan-create-head', $draft);
        self::assertStringContainsString('cp-plan-create-head__title', $draft);
        self::assertStringContainsString('idea_candidate_tab_available', $draft);
        self::assertStringContainsString('idea_candidate_tab_ai_short', $draft);
        self::assertStringContainsString('data-idea-total-badge="1"', $draft);
        self::assertStringContainsString('data-create-tab="ideas"', $draft);
        self::assertStringContainsString('data-create-tab="ai"', $draft);
        self::assertStringContainsString('.cp-plan-create-head', $css);
        self::assertStringContainsString("'idea_candidate_tab_ai_short'", $en);
        self::assertStringContainsString("'idea_candidate_tab_ai_short'", $vi);
        self::assertStringContainsString("'idea_candidate_action_create_short'", $en);
        self::assertStringContainsString("'idea_candidate_action_create_short'", $vi);
    }

    public function test_draft_jump_always_visible_as_planning_pool(): void
    {
        $page = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringContainsString('data-section-jump="draft"', $page);
        self::assertStringContainsString('content_planning_jump_to_draft', $page);
        self::assertStringContainsString('$draftAllCount', $page);
        self::assertStringContainsString('jumpToDraft()', $page);
        self::assertStringNotContainsString('@if ($draftAllCount > 0)', $page);
        self::assertStringNotContainsString('content-project-site-planning', $page);
    }

    public function test_site_planning_inline_detail_and_summary_use_read_model_fields(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-site-planning.blade.php');
        $readModel = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('sitePlanningPayload', $blade);
        self::assertStringContainsString('sitePlanningCellDetail', $blade);
        self::assertStringContainsString('data-site-planning-detail="1"', $blade);
        self::assertStringContainsString('data-site-planning-topic-history="1"', $blade);
        self::assertStringContainsString('detail.topics', $blade);
        self::assertStringContainsString('topic.topic_name', $blade);
        self::assertStringContainsString('planned_article_count', $blade);
        self::assertStringNotContainsString('cp-site-planning__drawer', $blade);
        self::assertStringNotContainsString('recomputeCluster', $blade);
        self::assertStringNotContainsString('keyword_id ===', $blade);
        self::assertStringContainsString('function cellDetail', $readModel);
        self::assertStringContainsString('SitePlanningActiveUnitAggregator', $readModel);
        self::assertStringContainsString('TopicHistoryReadModel', $readModel);
        self::assertStringNotContainsString('SitePlanningMonthCoverageReadModel', $readModel);
    }

    public function test_capacity_rejection_maps_to_human_messages(): void
    {
        $notifier = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectActionResultNotifier::class))->getFileName(),
        );
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDraftSplit::class))->getFileName(),
        );
        $en = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php');
        $vi = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php');

        self::assertSame('writer.capacity_exceeded', ContentProjectActionCodes::WRITER_CAPACITY_EXCEEDED);
        self::assertSame('writer.system_user_rejected', ContentProjectActionCodes::SYSTEM_USER_REJECTED);

        self::assertStringContainsString('WRITER_CAPACITY_EXCEEDED', $notifier);
        self::assertStringContainsString('SYSTEM_USER_REJECTED', $notifier);
        self::assertStringContainsString('writer_capacity_exceeded', $notifier);
        self::assertStringContainsString('writer_system_user_rejected', $notifier);

        self::assertStringContainsString('mapWriterCapacityMessage', $trait);
        self::assertStringContainsString('failDraftSplit', $trait);
        self::assertStringContainsString('WRITER_CAPACITY_EXCEEDED', $trait);
        self::assertStringContainsString('SYSTEM_USER_REJECTED', $trait);

        self::assertStringContainsString("'writer_capacity_exceeded'", $en);
        self::assertStringContainsString("'writer_system_user_rejected'", $en);
        self::assertStringContainsString("'writer_capacity_exceeded'", $vi);
        self::assertStringContainsString("'writer_system_user_rejected'", $vi);
    }

    public function test_draft_split_does_not_author_capacity_in_ui(): void
    {
        $gate = (string) file_get_contents(
            (string) (new ReflectionClass(WriterMonthlyCapacityGate::class))->getFileName(),
        );
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDraftSplit::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectWriterMonthlyCapacityService', $gate);
        self::assertStringContainsString('assertCanAccept', $gate);
        // UI may read remaining via capacity service for display; must not run the gate itself.
        self::assertStringNotContainsString('WriterMonthlyCapacityGate', $trait);
        self::assertStringNotContainsString('assertCanAccept', $trait);
        self::assertStringNotContainsString('assertProjectCanAccept', $trait);
        self::assertStringContainsString('SplitDraftContentProjectCommand', $trait);
        self::assertStringContainsString('mapWriterCapacityMessage', $trait);
    }

    public function test_active_month_chip_and_hint_in_global_bar(): void
    {
        $bar = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/views/livewire/global-seo-bar.blade.php',
        );
        $en = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php');
        $vi = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php');

        self::assertStringContainsString('cp-planner-month-chip', $bar);
        self::assertStringContainsString('data-planner-active-month="1"', $bar);
        self::assertStringContainsString('planner_active_month_hint', $bar);
        self::assertStringContainsString("'planner_active_month' => 'Active month'", $en);
        self::assertStringContainsString("'planner_active_month' => 'Tháng active'", $vi);
        self::assertStringContainsString("'planner_active_month_hint'", $en);
        self::assertStringContainsString("'planner_active_month_hint'", $vi);
    }

    public function test_idea_create_action_disables_while_loading(): void
    {
        $picker = LegacyAddonPath::read('resources/views/components/content-project-idea-candidate-picker.blade.php');

        self::assertStringContainsString('wire:loading.attr="disabled"', $picker);
        self::assertStringContainsString('wire:target="addIdeaCandidatesAsCreate"', $picker);
        self::assertStringContainsString('@disabled(! $actionsEnabled)', $picker);
        self::assertStringContainsString('wire:target="dismissIdeaCandidate', $picker);
    }

    public function test_search_stays_server_side_on_idea_candidates(): void
    {
        $picker = LegacyAddonPath::read('resources/views/components/content-project-idea-candidate-picker.blade.php');
        $concern = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithIdeaCandidates::class))->getFileName(),
        );

        self::assertStringContainsString('wire:submit="applyIdeaCandidateSearch"', $picker);
        self::assertStringContainsString('wire:model="ideaCandidateSearchInput"', $picker);
        self::assertStringContainsString('applyIdeaCandidateSearch', $concern);
        self::assertStringNotContainsString('rows.filter(', $picker);
        self::assertStringNotContainsString('.includes(search', $picker);
    }

    public function test_notifier_capacity_codes_prefer_i18n_over_raw_message(): void
    {
        $notifier = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectActionResultNotifier::class))->getFileName(),
        );

        self::assertStringContainsString('WRITER_CAPACITY_EXCEEDED', $notifier);
        self::assertStringContainsString('SYSTEM_USER_REJECTED', $notifier);
        self::assertStringContainsString('function mapBusinessMessage', $notifier);
        self::assertStringContainsString("__('seo-content-ai::filament.projects.writer_capacity_exceeded')", $notifier);
        self::assertStringContainsString("__('seo-content-ai::filament.projects.writer_system_user_rejected')", $notifier);
        self::assertStringContainsString("__('seo-content-ai::filament.projects.writer_capacity_exceeded_title')", $notifier);
        self::assertStringContainsString("__('seo-content-ai::filament.projects.writer_system_user_rejected_title')", $notifier);
    }
}