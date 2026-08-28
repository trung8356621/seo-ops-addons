<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleSeoAuditSkipService;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectSuggestionDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\FillSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipSeoAuditArticlesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ArchiveProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\FillSeoAuditSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\SkipSeoAuditArticlesHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditExistingContentSuggestionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionFilterSet;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

/**
 * Draft Content Planner Core — SEO Audit automation contracts.
 */
final class DraftContentPlannerCoreContractTest extends TestCase
{
    public function test_default_filter_set_excludes_page_and_uses_primary(): void
    {
        $defaults = SeoAuditSuggestionFilterSet::defaults();

        self::assertSame('primary', $defaults['language_scope']);
        self::assertSame(SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL_EXCEPT_PAGE, $defaults['post_type_mode']);
        self::assertTrue($defaults['exclude_taxonomy_archives']);
        self::assertTrue($defaults['exclude_skip_seo_audit']);
        self::assertSame(SeoAuditExistingContentSuggestionService::STATE_AVAILABLE, $defaults['state']);
    }

    public function test_filter_snapshot_is_immutable_round_trip(): void
    {
        $filters = SeoAuditSuggestionFilterSet::normalize([
            'score_max' => 60,
            'post_type_mode' => SeoAuditSuggestionFilterSet::POST_TYPE_MODE_SPECIFIC,
            'post_type' => 'product',
            'taxonomy' => 'product_cat',
            'term_id' => 42,
            'language_scope' => 'primary',
        ]);

        $snapshot = SeoAuditSuggestionFilterSet::snapshot($filters, 'vi');
        self::assertSame('vi', $snapshot['language']);
        self::assertSame(['product'], $snapshot['post_types']);
        self::assertSame('product_category', $snapshot['taxonomy']);
        self::assertSame(42, $snapshot['term_id']);
        self::assertSame(60, $snapshot['seo_score']['score_max']);

        $restored = SeoAuditSuggestionFilterSet::fromSnapshot($snapshot);
        self::assertSame(60, $restored['score_max']);
        self::assertSame('product', $restored['post_type']);
        self::assertSame('product_category', $restored['taxonomy']);
        self::assertSame(42, $restored['term_id']);
    }

    public function test_suggestion_service_applies_skip_page_and_entity_scopes(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/SeoAudit/SeoAuditExistingContentSuggestionService.php',
        );

        self::assertStringContainsString('applySkipSeoAuditScope', $src);
        self::assertStringContainsString('applyEntityAndPostTypeScopes', $src);
        self::assertStringContainsString('applyStateSqlScope', $src);
        self::assertStringContainsString('wp_post_type', $src);
        self::assertStringContainsString("'page'", $src);
        self::assertStringContainsString('exclude_taxonomy_archives', $src);
        self::assertStringContainsString('ArticleSeoAuditSkipService', $src);
        self::assertStringContainsString('public_url', $src);
    }

    public function test_planner_run_table_and_origin_linkage_exist(): void
    {
        $runMigration = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_24_180000_create_seo_content_project_planner_runs_table.php',
        );
        $originMigration = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_24_180100_add_planner_run_id_to_seo_content_project_item_origins_table.php',
        );

        self::assertStringContainsString('seo_content_project_planner_runs', $runMigration);
        self::assertStringContainsString('configuration_snapshot', $runMigration);
        self::assertStringContainsString('result_summary', $runMigration);
        self::assertStringContainsString('source_type', $runMigration);
        self::assertStringContainsString('planner_run_id', $originMigration);

        self::assertSame('seo_audit', SeoContentProjectPlannerRun::SOURCE_SEO_AUDIT);
        self::assertSame('ai_new_content', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT);
        self::assertSame('seo_audit', SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT);
        self::assertSame('ai_new_content', SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT);
    }

    public function test_rejection_memory_stays_on_suggestion_decisions(): void
    {
        self::assertSame('dismissed', SeoContentProjectSuggestionDecision::DECISION_DISMISSED);
        self::assertSame('seo_audit', SeoContentProjectSuggestionDecision::SOURCE_SEO_AUDIT);

        $archive = (string) file_get_contents(
            (string) (new ReflectionClass(ArchiveProjectItemsHandler::class))->getFileName(),
        );
        self::assertStringContainsString('dismissArticles', $archive);
        self::assertStringContainsString('isDraftPlanning()', $archive);
        self::assertStringContainsString('never skip_seo_audit', $archive);
        self::assertStringNotContainsString('ArticleSeoAuditSkipService', $archive);
        self::assertStringNotContainsString('toggleSkipSeoAudit', $archive);
    }

    public function test_global_skip_reuses_article_meta_service(): void
    {
        self::assertSame('skip_seo_audit', ArticleSeoAuditSkipService::META_KEY);

        $handler = (string) file_get_contents(
            (string) (new ReflectionClass(SkipSeoAuditArticlesHandler::class))->getFileName(),
        );
        self::assertStringContainsString('ArticleSeoAuditSkipService', $handler);
        self::assertStringContainsString('skipMany', $handler);

        self::assertSame('content_project.skip_seo_audit_articles', (new SkipSeoAuditArticlesCommand(1, [1]))->name());
    }

    public function test_fill_records_planner_run_and_result_counts(): void
    {
        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAuditSuggestionPlannerService::class))->getFileName(),
        );
        $handler = (string) file_get_contents(
            (string) (new ReflectionClass(FillSeoAuditSuggestionsHandler::class))->getFileName(),
        );
        $runService = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPlannerRunService::class))->getFileName(),
        );

        self::assertStringContainsString('recordExecuted', $planner);
        self::assertStringContainsString('planner_run_id', $planner);
        self::assertStringContainsString('unavailable', $planner);
        self::assertStringContainsString('matched', $planner);
        self::assertStringContainsString('unavailable under current filters', $handler);
        self::assertStringContainsString('listExecuted', $runService);
        self::assertStringContainsString('recordSavedConfig', $runService);
        self::assertSame('content_project.fill_seo_audit_suggestions', (new FillSeoAuditSuggestionsCommand(1))->name());
    }

    public function test_draft_planner_and_audit_ui_contracts(): void
    {
        $draftPlanner = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $auditPlanner = LegacyAddonPath::read('resources/views/components/content-project-seo-audit-planner.blade.php');
        $auditPage = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $ops = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php');

        self::assertStringContainsString('planner_fill_from_seo_audit', $draftPlanner);
        self::assertStringContainsString('planner_improve_heading', $draftPlanner);
        self::assertStringContainsString('content-project-new-content-card', $draftPlanner);
        self::assertStringContainsString('filtersOpen: true', $draftPlanner);
        self::assertStringNotContainsString('historyOpen', $draftPlanner);
        self::assertStringNotContainsString('planner_history', $draftPlanner);
        self::assertStringContainsString('content_planning_recent_filters', $draftPlanner);
        self::assertStringContainsString('data-draft-action="split"', $draftPlanner);
        self::assertStringContainsString('data-draft-action="activate-all"', $draftPlanner);
        self::assertStringContainsString('seoAuditPlannerCardState', $draftPlanner);
        self::assertStringNotContainsString('planner_matched_count', $draftPlanner);

        self::assertStringContainsString('data-seo-audit-filter-row="1"', $auditPlanner);
        self::assertStringContainsString('data-seo-audit-filter-row="2"', $auditPlanner);
        self::assertStringContainsString('data-filter="search"', $auditPlanner);
        self::assertStringContainsString('data-filter="score"', $auditPlanner);
        self::assertStringContainsString('data-filter="language"', $auditPlanner);
        self::assertStringContainsString('data-filter="issue"', $auditPlanner);
        self::assertStringContainsString('data-filter="action"', $auditPlanner);
        self::assertStringContainsString('data-filter="state"', $auditPlanner);
        self::assertStringContainsString('data-filter="post_type"', $auditPlanner);
        self::assertStringContainsString('data-filter="taxonomy"', $auditPlanner);
        self::assertStringContainsString('data-filter="term"', $auditPlanner);
        self::assertStringContainsString('data-title-link=', $auditPlanner);
        self::assertStringContainsString('skipSuggestionFromSeoAudit', $auditPlanner);
        self::assertStringContainsString('public_url', $auditPlanner);

        self::assertStringContainsString('content-project-draft-planner', $auditPage);
        self::assertStringContainsString('content_planning_subtitle', $auditPage);
        self::assertStringNotContainsString('seo_audit_advanced_help', $auditPage);
        self::assertStringNotContainsString('<h1', $auditPage);

        self::assertStringContainsString('content-project-draft-planner', $ops);
        self::assertStringContainsString('planner_draft_items', $ops);
    }

    public function test_lang_keys_exist_for_planner(): void
    {
        $en = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php');
        $vi = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php');

        foreach ([
            'planner_fill_from_seo_audit',
            'planner_seo_audit_history',
            'seo_audit_skip_confirm',
            'item_action_remove_from_draft',
            'item_action_skip_seo_audit',
            'seo_audit_advanced_help',
        ] as $key) {
            self::assertStringContainsString("'".$key."'", $en);
            self::assertStringContainsString("'".$key."'", $vi);
        }
    }

    public function test_command_bus_registers_skip_and_fill(): void
    {
        $registrar = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );
        self::assertStringContainsString('FillSeoAuditSuggestionsCommand::class => FillSeoAuditSuggestionsHandler::class', $registrar);
        self::assertStringContainsString('SkipSeoAuditArticlesCommand::class => SkipSeoAuditArticlesHandler::class', $registrar);
        self::assertStringContainsString('SplitDraftContentProjectCommand::class => SplitDraftContentProjectHandler::class', $registrar);
    }

    public function test_capabilities_and_factory_expose_fill_and_skip(): void
    {
        $registry = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Capabilities/ContentProjectCapabilityRegistry.php',
        );
        $factory = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Agent/ContentProjectAgentCommandFactory.php',
        );

        self::assertStringContainsString("'content_project.fill_seo_audit_suggestions'", $registry);
        self::assertStringContainsString("'content_project.skip_seo_audit_articles'", $registry);
        self::assertStringContainsString("'content_project.split_draft'", $registry);
        self::assertStringContainsString("'content_project.fill_seo_audit_suggestions' =>", $factory);
        self::assertStringContainsString("'content_project.skip_seo_audit_articles' =>", $factory);
        self::assertStringContainsString("'content_project.split_draft' =>", $factory);
    }

    public function test_global_skip_meta_key_unchanged(): void
    {
        self::assertSame(
            \Omnichannel\Addons\Content\Filament\Resources\ArticleResource::META_SKIP_SEO_AUDIT,
            ArticleSeoAuditSkipService::META_KEY,
        );
    }

    public function test_draft_execution_still_blocked(): void
    {
        $generate = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Handlers/GenerateProjectItemsHandler.php',
        );
        self::assertStringContainsString('rejectIfDraft', $generate);
    }
}
