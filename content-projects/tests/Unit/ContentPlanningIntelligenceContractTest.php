<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceCaps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionContextBuilder;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionDedupFilter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\ContentProjects\Services\CreateArticlesFromTaskService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 6 — Planning Intelligence contracts (deterministic, 0 AI in planning).
 */
final class ContentPlanningIntelligenceContractTest extends TestCase
{
    public function test_caps_are_centralized(): void
    {
        self::assertSame(100, ContentPlanningIntelligenceCaps::PRINCIPAL_KEYWORDS);
        self::assertSame(30, ContentPlanningIntelligenceCaps::CLUSTERS);
        self::assertSame(100, ContentPlanningIntelligenceCaps::EXISTING_TOPICS);
        self::assertSame(100, ContentPlanningIntelligenceCaps::PLANNED_TOPICS);
        self::assertSame(100, ContentPlanningIntelligenceCaps::REJECTED_TOPICS);
        self::assertSame(30, ContentPlanningIntelligenceCaps::MCP_SIGNALS);
        self::assertSame(30, ContentPlanningIntelligenceCaps::GSC_SIGNALS);
        self::assertContains('sentence', ContentPlanningIntelligenceCaps::EXCLUDED_PHRASE_KINDS);
        self::assertContains('noise', ContentPlanningIntelligenceCaps::EXCLUDED_PHRASE_KINDS);
    }

    public function test_context_builder_delegates_to_planning_intelligence(): void
    {
        $builder = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionContextBuilder::class))->getFileName(),
        );
        self::assertStringContainsString('ContentPlanningIntelligenceService', $builder);
        self::assertStringContainsString('covered_keyword_norms', $builder);
        self::assertStringContainsString('renderBrief', $builder);
        self::assertStringNotContainsString('McpAiContextBuilder', $builder);
        self::assertStringNotContainsString('keywordIntelligencePhrases', $builder);
    }

    public function test_planning_service_filters_seo_keywords_not_primary_language(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentPlanningIntelligenceService::class))->getFileName(),
        );
        self::assertStringContainsString("is_seo_keyword", $src);
        self::assertStringContainsString('MIN_KEYWORD_SCORE', $src);
        self::assertStringContainsString('EXCLUDED_PHRASE_KINDS', $src);
        self::assertStringNotContainsString("where('language'", $src);
        self::assertStringNotContainsString('where("language"', $src);
        self::assertStringNotContainsString('KeywordPersistenceService', $src);
        self::assertStringNotContainsString('CreateArticlesFromTaskService', $src);
        self::assertStringNotContainsString('WorkflowKeywordResearchService', $src);
        self::assertStringNotContainsString('PromptRunnerService', $src);
        self::assertStringNotContainsString('PromptHookCallerBridge', $src);
        self::assertStringContainsString('gsc_signals', $src);
        self::assertStringContainsString('gscSignals', $src);
        self::assertStringContainsString('McpSourceKey::Gsc', $src);
        self::assertStringContainsString('improvement_signal', $src);
    }

    public function test_planning_absent_gsc_is_non_blocking_contract(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentPlanningIntelligenceService::class))->getFileName(),
        );
        self::assertStringContainsString("'gsc_signals' => \$gscSignals", $src);
        self::assertStringContainsString('GSC_SIGNALS', $src);
    }

    public function test_dedup_blocks_covered_not_bare_ki_inventory(): void
    {
        $parser = new NewContentSuggestionParser;
        $parsed = $parser->parse([
            ['keyword' => 'covered phrase', 'suggested_title' => 'A'],
            ['keyword' => 'uncovered ki only', 'suggested_title' => 'B'],
        ], 10);
        $filter = new NewContentSuggestionDedupFilter;
        $out = $filter->filter(
            $parsed['candidates'],
            [],
            [],
            [NewContentSuggestionIdentity::normalize('covered phrase') => true],
        );

        self::assertCount(1, $out['accepted']);
        self::assertSame('uncovered ki only', $out['accepted'][0]['keyword']);
        self::assertSame(1, $out['existing_skipped']);
    }

    public function test_dedup_blocks_planned_and_rejected_fingerprints(): void
    {
        $parser = new NewContentSuggestionParser;
        $parsed = $parser->parse([
            ['keyword' => 'planned', 'suggested_title' => 'A'],
            ['keyword' => 'rejected', 'suggested_title' => 'B'],
            ['keyword' => 'fresh', 'suggested_title' => 'C'],
        ], 10);
        $planned = NewContentSuggestionIdentity::fingerprint('planned', 'A');
        $rejected = NewContentSuggestionIdentity::fingerprint('rejected', 'B');
        $out = (new NewContentSuggestionDedupFilter)->filter(
            $parsed['candidates'],
            [$planned => true],
            [$rejected => true],
            [],
        );

        self::assertCount(1, $out['accepted']);
        self::assertSame('fresh', $out['accepted'][0]['keyword']);
        self::assertSame(1, $out['rejected_skipped']);
    }

    public function test_parser_keeps_explainability_fields(): void
    {
        $parsed = (new NewContentSuggestionParser)->parse([
            [
                'keyword' => 'balo chong nuoc',
                'suggested_title' => 'Title',
                'suggestion_reason' => 'Weak cluster coverage',
                'source_signal' => 'cluster_gap',
            ],
            [
                'keyword' => 'noise signal',
                'suggested_title' => 'T2',
                'source_signal' => 'made_up_signal',
            ],
        ], 10);

        self::assertSame('Weak cluster coverage', $parsed['candidates'][0]['suggestion_reason']);
        self::assertSame('cluster_gap', $parsed['candidates'][0]['source_signal']);
        self::assertSame('', $parsed['candidates'][1]['source_signal']);
    }

    public function test_planner_stores_compact_planning_diagnostics_and_one_ai_call(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringContainsString("'planning_context'", $src);
        self::assertStringContainsString('principal_keywords_count', $src);
        self::assertStringContainsString("'planning_ai_calls' => 0", $src);
        self::assertStringContainsString("'logical_ai_calls' => \$this->logicalDiscoveryCalls", $src);
        self::assertStringContainsString('suggestion_reason', $src);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\SearchFoundation\\Services\\KeywordPersistenceService', $src);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\ContentProjects\\Services\\CreateArticlesFromTaskService', $src);
        self::assertTrue(class_exists(KeywordPersistenceService::class));
        self::assertTrue(class_exists(CreateArticlesFromTaskService::class));
    }

    public function test_agent_read_capability_is_registered(): void
    {
        self::assertContains('content_project.planning_intelligence', ContentProjectAgentGateway::READ_CAPABILITIES);
        $gateway = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentGateway::class))->getFileName(),
        );
        $reads = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentReadService::class))->getFileName(),
        );
        self::assertStringContainsString("'content_project.planning_intelligence'", $gateway);
        self::assertStringContainsString('getPlanningIntelligence', $reads);
        self::assertStringContainsString('ContentPlanningIntelligenceService', $reads);
    }

    public function test_render_brief_mentions_cross_lane_avoidance(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentPlanningIntelligenceService::class))->getFileName(),
        );
        self::assertStringContainsString('Rewrite/Improve', $src);
        self::assertStringContainsString('mcp_signals', $src);
        self::assertStringContainsString('gsc_signals', $src);
        self::assertStringContainsString('GSC impressions are Search Console impressions', $src);
        self::assertStringContainsString('covered_keyword_norms', $src);
    }

    public function test_hook_documents_optional_explainability(): void
    {
        $hook = (string) file_get_contents(
            \Tests\Support\ProjectRoot::addonsPath().'/ai-prompt/resources/prompt-hooks/v01/keyword.discovery.structured@0.1.0.json',
        );
        self::assertStringContainsString('suggestion_reason', $hook);
        self::assertStringContainsString('source_signal', $hook);
    }

    public function test_ui_exposes_planning_context_preview(): void
    {
        $card = \Tests\Support\LegacyAddonPath::read('resources/views/components/content-project-new-content-card.blade.php');
        $detail = \Tests\Support\LegacyAddonPath::read('resources/views/filament/pages/content-project-planner-run-detail.blade.php');
        self::assertStringContainsString('content_planning_chip_kw_clusters', $card);
        self::assertStringContainsString('newContentPlanningPreview', $card);
        self::assertStringContainsString('data-planning-intelligence="1"', $card);
        self::assertStringContainsString('suggestion_reason', $detail);
        self::assertStringContainsString('planner_decision', $detail);
    }
}
