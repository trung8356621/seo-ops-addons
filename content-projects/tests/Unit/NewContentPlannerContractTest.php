<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectNewContentPlanner;
use Omnichannel\Addons\ContentProjects\Jobs\GenerateNewContentSuggestionsJob;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectSuggestionDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ArchiveProjectItemsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateNewContentSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionDedupFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

/**
 * Draft Content Planner Phase 2 — AI New Content contracts.
 */
final class NewContentPlannerContractTest extends TestCase
{
    public function test_identity_fingerprint_is_deterministic_and_case_insensitive(): void
    {
        $a = NewContentSuggestionIdentity::fingerprint('Balo Học Sinh', 'Cách chọn balo');
        $b = NewContentSuggestionIdentity::fingerprint('balo học sinh', 'cách chọn balo!');
        self::assertSame($a, $b);
        self::assertSame(64, strlen($a));
        self::assertSame('fp:'.$a, NewContentSuggestionIdentity::decisionSourceKey($a));
    }

    public function test_options_clamp_quantity_and_default_post_type(): void
    {
        $opts = NewContentSuggestionOptions::normalize([
            'quantity' => 10000,
            'post_type' => 'page',
            'direction' => 'weird',
        ]);
        self::assertSame(100, $opts['quantity']);
        self::assertSame('post', $opts['post_type']);
        self::assertSame('automatic', $opts['direction']);

        $snapshot = NewContentSuggestionOptions::snapshot(['quantity' => 20, 'focus' => 'balo học sinh'], 'vi');
        self::assertSame('vi', $snapshot['primary_language']);
        self::assertSame('balo học sinh', $snapshot['notes']);
        $restored = NewContentSuggestionOptions::fromSnapshot(['quantity' => 20, 'focus' => 'balo học sinh']);
        self::assertSame(20, $restored['quantity']);
        self::assertSame('balo học sinh', $restored['notes']);
    }

    public function test_parser_accepts_string_and_object_shapes(): void
    {
        $parser = new NewContentSuggestionParser;
        $parsed = $parser->parse([
            'keyword only',
            ['keyword' => 'kw2', 'suggested_title' => 'Title 2'],
            ['keyword' => 'kw3', 'title_idea' => 'Title 3'],
            ['title' => 'Title only'],
            ['nope' => true],
        ], 20);

        self::assertSame(5, $parsed['generated']);
        self::assertSame(1, $parsed['invalid']);
        self::assertCount(4, $parsed['candidates']);
        self::assertSame('Title 2', $parsed['candidates'][1]['title']);
        self::assertSame('Title only', $parsed['candidates'][3]['keyword']);
    }

    public function test_dedup_respects_planned_rejected_and_existing(): void
    {
        $parser = new NewContentSuggestionParser;
        $parsed = $parser->parse([
            ['keyword' => 'planned', 'suggested_title' => 'A'],
            ['keyword' => 'rejected', 'suggested_title' => 'B'],
            ['keyword' => 'existing', 'suggested_title' => 'C'],
            ['keyword' => 'fresh', 'suggested_title' => 'D'],
        ], 20);
        $filter = new NewContentSuggestionDedupFilter;
        $plannedFp = NewContentSuggestionIdentity::fingerprint('planned', 'A');
        $rejectedFp = NewContentSuggestionIdentity::fingerprint('rejected', 'B');
        $out = $filter->filter(
            $parsed['candidates'],
            [$plannedFp => true],
            [$rejectedFp => true],
            [NewContentSuggestionIdentity::normalize('existing') => true],
        );

        self::assertCount(1, $out['accepted']);
        self::assertSame('fresh', $out['accepted'][0]['keyword']);
        self::assertSame(2, $out['duplicate_skipped']);
        self::assertSame(1, $out['rejected_skipped']);
        self::assertSame(1, $out['duplicate_breakdown']['active_draft']);
        self::assertSame(1, $out['duplicate_breakdown']['covered_content']);
        $statuses = array_column($out['results'], 'status');
        self::assertContains(NewContentSuggestionDedupFilter::STATUS_DUPLICATE_DRAFT, $statuses);
        self::assertContains(NewContentSuggestionDedupFilter::STATUS_DUPLICATE_COVERED_CONTENT, $statuses);
        self::assertContains(NewContentSuggestionDedupFilter::STATUS_PROJECT_REJECTED, $statuses);
        self::assertContains(NewContentSuggestionDedupFilter::STATUS_ADDED, $statuses);
    }

    public function test_planner_avoids_legacy_keyword_and_article_writers(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString("keyword.discovery.structured", $src);
        self::assertStringContainsString('PromptHookCallerBridge', $src);
        self::assertStringContainsString('logicalDiscoveryCalls', $src);
        self::assertStringContainsString("'planning_context' => \$brief", $src);
        self::assertStringContainsString("'requested_quantity' => \$quantity", $src);
        self::assertStringContainsString("'notes' => \$notesValue", $src);
        self::assertStringContainsString('importFromExistingRun', $src);
        self::assertStringContainsString("'logical_ai_calls' => 0", $src);
        self::assertStringContainsString('source_content', $src);
        self::assertStringContainsString("'post_type' => \$contentType", $src);
        self::assertStringContainsString('loai_san_pham', $src);
        self::assertStringContainsString('secondary_description', $src);
        self::assertStringContainsString('gallery_description', $src);
        self::assertStringContainsString('lastDiscoveryPromptResultId', $src);
        self::assertStringContainsString('Draft persist failed:', $src);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\SearchFoundation\\Services\\KeywordPersistenceService', $src);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\ContentProjects\\Services\\CreateArticlesFromTaskService', $src);
        self::assertDoesNotMatchRegularExpression('/\bauth\s*\(/', $src);
        self::assertStringNotContainsString('AiKeywordDiscoveryService', $src);

        self::assertTrue(class_exists(KeywordPersistenceService::class));
        self::assertTrue(class_exists(CreateArticlesFromTaskService::class));
    }

    public function test_job_passes_explicit_actor_and_unique_per_project(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GenerateNewContentSuggestionsJob::class))->getFileName(),
        );
        self::assertStringContainsString('public readonly int $actorId', $src);
        self::assertStringContainsString('content-project-new-content:', $src);
        self::assertStringContainsString('executeQueuedRun', $src);
        self::assertDoesNotMatchRegularExpression('/\bauth\s*\(/', $src);
    }

    public function test_command_handler_queues_job_and_requires_primary_language(): void
    {
        $handler = (string) file_get_contents(
            (string) (new ReflectionClass(GenerateNewContentSuggestionsHandler::class))->getFileName(),
        );
        self::assertStringContainsString('GenerateNewContentSuggestionsJob::dispatch', $handler);
        self::assertStringContainsString('PRIMARY_LANGUAGE_MISSING', $handler);
        self::assertStringContainsString('queueGeneration', $handler);
        self::assertSame(
            'content_project.generate_new_content_suggestions',
            (new GenerateNewContentSuggestionsCommand(1, 20))->name(),
        );
    }

    public function test_archive_dismisses_ai_fingerprints_on_draft_remove(): void
    {
        $archive = (string) file_get_contents(
            (string) (new ReflectionClass(ArchiveProjectItemsHandler::class))->getFileName(),
        );
        self::assertStringContainsString('dismissFingerprints', $archive);
        self::assertStringContainsString('SOURCE_AI_NEW_CONTENT', $archive);
        self::assertStringContainsString('never skip_seo_audit', $archive);
        self::assertStringContainsString('Without article_id there is no article workspace', $archive);
        self::assertSame('ai_new_content', SeoContentProjectSuggestionDecision::SOURCE_AI_NEW_CONTENT);
        self::assertSame('ai_new_content', SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT);
    }

    public function test_planner_run_supports_queued_running_status(): void
    {
        self::assertSame('queued', SeoContentProjectPlannerRun::STATUS_QUEUED);
        self::assertSame('running', SeoContentProjectPlannerRun::STATUS_RUNNING);
        $svc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPlannerRunService::class))->getFileName(),
        );
        self::assertStringContainsString('recordQueued', $svc);
        self::assertStringContainsString('findActive', $svc);
        self::assertStringContainsString('completeRun', $svc);
        self::assertStringContainsString('failRun', $svc);
    }

    public function test_ui_card_and_global_page_share_component(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $global = LegacyAddonPath::read('resources/views/filament/pages/content-project-new-content-planner.blade.php');
        $card = LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');
        $planning = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectNewContentPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('content-project-new-content-card', $draft);
        self::assertStringContainsString('content_planning_redirecting', $global);
        self::assertStringContainsString('content-project-draft-planner', $planning);
        self::assertStringContainsString('generateNewContentSuggestions', $card);
        self::assertStringContainsString('planner_content_type', $card);
        self::assertStringNotContainsString('planner_options', $card);
        self::assertStringNotContainsString('planner_save_options', $card);
        self::assertStringNotContainsString('planner_history', $card);
        self::assertStringContainsString('draft_ai_history_link', $card);
        self::assertStringContainsString('data-planner-content-type="1"', $card);
        self::assertStringContainsString('data-planner-notes="new-content"', $card);
        self::assertStringContainsString('newContentNotes', $card);
        self::assertStringContainsString('wire:model="newContentNotes"', $card);
        self::assertStringNotContainsString('wire:model.live="newContentNotes"', $card);
        self::assertStringNotContainsString('newContentFocus', $card);
        self::assertStringNotContainsString('planner_create_phase2', $draft);
        self::assertStringContainsString("slug = 'content-projects/new-content'", $page);
        self::assertStringContainsString('ContentProjectSeoAuditPlanner::getUrl', $page);
        self::assertStringContainsString('shouldRegisterNavigation = false', $page);
    }

    public function test_nav_orders_seo_audit_new_content_publishing_queue(): void
    {
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource.php',
        );
        self::assertStringContainsString('ContentProjectSeoAuditPlanner::getUrl()', $resource);
        self::assertStringContainsString('PublishingQueueHub::getUrl()', $resource);
        self::assertStringNotContainsString('ContentProjectNewContentPlanner::getUrl()', $resource);
        $auditPos = strpos($resource, 'ContentProjectSeoAuditPlanner::getUrl()');
        $queuePos = strpos($resource, 'PublishingQueueHub::getUrl()');
        self::assertNotFalse($auditPos);
        self::assertNotFalse($queuePos);
        self::assertLessThan($queuePos, $auditPos);
    }

    public function test_capabilities_and_factory_expose_generate_new_content(): void
    {
        $registry = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/Capabilities/ContentProjectCapabilityRegistry.php',
        );
        $factory = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Agent/ContentProjectAgentCommandFactory.php',
        );
        $registrar = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );
        $gateway = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/Agent/ContentProjectAgentGateway.php',
        );

        self::assertStringContainsString("'content_project.generate_new_content_suggestions'", $registry);
        self::assertStringContainsString('Does not generate articles', $registry);
        self::assertStringContainsString("'content_project.generate_new_content_suggestions' =>", $factory);
        self::assertStringContainsString(
            'GenerateNewContentSuggestionsCommand::class => GenerateNewContentSuggestionsHandler::class',
            $registrar,
        );
        self::assertStringContainsString('content_project.planning_intelligence', $gateway);
        self::assertStringContainsString('ContentPlanningIntelligenceService', (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/NewContent/NewContentSuggestionContextBuilder.php',
        ));
    }

    public function test_hook_requests_suggested_title_and_primary_language(): void
    {
        $hook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/keyword.discovery.structured@0.1.0.json',
        );
        self::assertStringContainsString('suggested_title', $hook);
        self::assertStringContainsString('primary_language', $hook);
        self::assertStringContainsString('content_type', $hook);
        self::assertStringContainsString('legacy_prompt_content', $hook);
        self::assertStringContainsString('No domain write', $hook);
        self::assertStringContainsString('"settings_visible": true', $hook);
    }

    public function test_planner_passes_content_type_into_discovery_envelope(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringContainsString("'content_type' => \$contentType", $src);
        self::assertStringContainsString('contentType:', $src);
        self::assertStringContainsString('Keyword Discovery prompt', $src);
    }

    public function test_item_menu_hides_skip_for_ai_and_omits_view_run(): void
    {
        $readModel = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/ContentProjectItemOperationsReadModel.php',
        );
        $draftItems = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');
        $menu = LegacyAddonPath::read('resources/views/components/content-project-item-actions-menu.blade.php');
        $presenter = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Support/ContentProject/ContentProjectItemActionsPresenter.php',
        );

        self::assertStringContainsString('SOURCE_SEO_AUDIT', $readModel);
        self::assertStringNotContainsString('can_view_generation_run', $readModel);
        self::assertStringNotContainsString('view_generation_run', $menu);
        self::assertStringNotContainsString('view_generation_run', $presenter);
        self::assertStringNotContainsString('can_view_generation_run', $draftItems);
        self::assertStringNotContainsString('planner_run_results_url', $draftItems);
        self::assertStringNotContainsString('viewDraftItemGenerationRun', $draftItems);
        self::assertStringContainsString('planner_run_id', $readModel);
    }

    public function test_lang_keys_for_new_content_exist(): void
    {
        $en = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/en/filament.php');
        $vi = (string) file_get_contents(ProjectRoot::addonsPath().'/seo-content-ai-compat/lang/vi/filament.php');
        foreach ([
            'planner_create_help',
            'planner_generate_with_ai',
            'planner_content_type',
            'planner_notes',
            'new_content_nav_label',
            'planner_decision_duplicate_in_batch_keyword',
            'planner_primary_language_missing',
        ] as $key) {
            self::assertStringContainsString("'".$key."'", $en);
            self::assertStringContainsString("'".$key."'", $vi);
        }
    }
}
