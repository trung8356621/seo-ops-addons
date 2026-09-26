<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\RunsTopicalMapAuditAndTags;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Http\Controllers\TopicalMap\TopicalMapAuditController;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto\TopicalMapOverview;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditContracts;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditResultParser;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditContextBuilder;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextGateway;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TopicalMapAuditContractTest extends TestCase
{
    public function test_hook_key_and_version_match_installer_0_3_0(): void
    {
        self::assertSame('seo_keywords.topical_map_audit', TopicalMapAuditService::HOOK_KEY);
        self::assertSame('0.3.0', TopicalMapAuditService::HOOK_VERSION);
        self::assertSame(TopicalMapAuditService::HOOK_KEY, DefaultTopicalMapAuditPromptInstaller::HOOK_KEY);
        self::assertSame(TopicalMapAuditService::HOOK_VERSION, DefaultTopicalMapAuditPromptInstaller::HOOK_VERSION);
    }

    public function test_historical_0_1_0_and_0_2_0_preserved_and_0_3_0_is_current_spec(): void
    {
        $base = dirname((string) (new ReflectionClass(DefaultTopicalMapAuditPromptInstaller::class))->getFileName(), 4)
            .'/resources/prompt-hooks/v01/';
        $v01 = $base.'seo_keywords.topical_map_audit@0.1.0.json';
        $v02 = $base.'seo_keywords.topical_map_audit@0.2.0.json';
        self::assertFileExists($v01);
        self::assertFileExists($v02);
        $old = json_decode((string) file_get_contents($v01), true);
        $mid = json_decode((string) file_get_contents($v02), true);
        self::assertSame('0.1.0', $old['version'] ?? null);
        self::assertSame('0.2.0', $mid['version'] ?? null);

        $spec = DefaultTopicalMapAuditPromptInstaller::loadCanonicalSpec();
        self::assertSame('seo_keywords.topical_map_audit', $spec['key'] ?? null);
        self::assertSame('0.3.0', $spec['version'] ?? null);
        self::assertArrayHasKey('mcp_markdown', $spec['input_schema'] ?? []);
        self::assertArrayHasKey('topical_map_json', $spec['input_schema'] ?? []);
        self::assertArrayHasKey('existing_topic_tags_json', $spec['input_schema'] ?? []);
        self::assertArrayHasKey('company_short_identity', $spec['input_schema'] ?? []);
        self::assertArrayHasKey('short_description', $spec['input_schema'] ?? []);
    }

    public function test_canonical_markdown_encodes_audit_rules_and_output_contract(): void
    {
        $markdown = DefaultTopicalMapAuditPromptInstaller::canonicalDefaultMarkdown();
        self::assertStringContainsString('do NOT create Topics', $markdown);
        self::assertStringContainsString('do NOT invent an SEO score', $markdown);
        self::assertStringContainsString('topic_ref', $markdown);
        self::assertStringContainsString('Company Short Identity', $markdown);
        self::assertStringContainsString('weak_coverage', $markdown);
        self::assertStringContainsString('investigate_new_topic', $markdown);
        self::assertStringContainsString('recommended_actions', $markdown);
        self::assertStringContainsString('tag_suggestions', $markdown);
        self::assertStringContainsString('{{mcp_markdown}}', $markdown);
        self::assertStringContainsString('{{topical_map_json}}', $markdown);
        self::assertStringContainsString('{{existing_topic_tags_json}}', $markdown);
        self::assertStringContainsString('{{company_short_identity}}', $markdown);
        self::assertStringNotContainsString('SEO score:', $markdown);
    }

    public function test_default_profile_is_reasoning_text_and_override_path_exists(): void
    {
        $resolver = new PromptExecutionProfileResolver;
        self::assertSame(
            AiExecutionProfile::TextReasoning,
            $resolver->resolve(null, 'seo_keywords.topical_map_audit'),
        );

        $src = (string) file_get_contents((string) (new ReflectionClass(PromptExecutionProfileResolver::class))->getFileName());
        self::assertStringContainsString('routing_profile_key', $src);
        self::assertStringContainsString('seo_keywords.topical_map_audit', $src);
    }

    public function test_output_budget_registered_as_business_split_not_default_512(): void
    {
        $registry = new \Omnichannel\Addons\AiPrompt\PromptBudget\PromptSplitStrategyRegistry;
        $strategy = $registry->forHook('seo_keywords.topical_map_audit');
        self::assertSame(
            \Omnichannel\Addons\AiPrompt\Support\PromptSplitClass::BusinessSplit,
            $strategy->splitClass(),
        );

        $reasoning = new \Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability(
            contextWindow: 128_000,
            maxOutputTokens: 8192,
            capabilitySource: 'test',
            estimatorFamily: \Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator::FAMILY_DEFAULT,
            isReasoningModel: true,
            safetyMarginTokens: 800,
        );
        self::assertSame(8192, $strategy->estimateOutputReserve([], $reasoning));
        self::assertGreaterThan(512, $strategy->estimateOutputReserve([], $reasoning));
    }

    public function test_audit_service_surfaces_prompt_result_id_and_user_message_on_routes_exhausted(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(TopicalMapAuditService::class))->getFileName());
        self::assertStringContainsString('failFromThrowable', $src);
        self::assertStringContainsString("context['prompt_result_id']", $src);
        self::assertStringContainsString('userMessage()', $src);
        self::assertStringContainsString('AI Audit failed:', $src);
        self::assertStringNotContainsString(
            "return \$this->fail('Topical Map audit failed: '.\$e->getMessage());",
            $src,
        );
    }

    public function test_audit_service_uses_context_builder_and_structural_projection_not_monthly_mcp(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(TopicalMapAuditService::class))->getFileName());
        self::assertStringContainsString('TopicalMapAuditContextBuilder', $src);
        self::assertStringContainsString('auditContext', $src);
        self::assertStringContainsString('buildMarkdown', $src);
        self::assertStringContainsString('SiteDomainPromptContextService', $src);
        self::assertStringContainsString('structuralProjection', $src);
        self::assertStringContainsString('EMPTY_MAP_MESSAGE', $src);
        self::assertStringContainsString('PromptHookCallerBridge', $src);
        self::assertStringContainsString('resolveEffectivePromptLanguage', $src);
        self::assertStringContainsString('?string $languageCode = null', $src);
        self::assertStringNotContainsString('McpAiContextBuilder', $src);
        self::assertStringNotContainsString('McpPeriodService', $src);
        self::assertStringNotContainsString('Services\\MonthlyMcp', $src);
        self::assertStringNotContainsString('SeoMcpSourceSnapshot', $src);
        self::assertStringNotContainsString('OpenAI', $src);
        self::assertStringNotContainsString('Anthropic', $src);
        self::assertStringNotContainsString('Http::', $src);
        self::assertStringNotContainsString('echarts', strtolower($src));
    }

    public function test_audit_context_builder_uses_canonical_gateways(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(TopicalMapAuditContextBuilder::class))->getFileName(),
        );
        self::assertStringContainsString('SiteContextGateway', $src);
        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringContainsString('GscContextGateway', $src);
        self::assertStringNotContainsString('McpAiContextBuilder', $src);
        self::assertStringNotContainsString('McpPeriodService', $src);
        self::assertStringNotContainsString('Services\\MonthlyMcp', $src);

        $ctor = (new ReflectionClass(TopicalMapAuditContextBuilder::class))->getConstructor();
        self::assertNotNull($ctor);
        $types = array_map(static fn ($p) => $p->getType()?->getName(), $ctor->getParameters());
        self::assertContains(SiteContextGateway::class, $types);
        self::assertContains(KeywordLandscapeGateway::class, $types);
        self::assertContains(GscContextGateway::class, $types);
    }

    public function test_structural_projection_adds_topic_ref_and_omits_chart_fields(): void
    {
        $overview = new TopicalMapOverview(
            siteId: 9,
            topics: [[
                'id' => 12,
                'name' => 'School bags',
                'mcp' => 42.5,
                'dna_count' => 3,
                'article_count' => 8,
                'keyword_count' => 20,
                'coverage' => 'partial',
                'status' => 'active',
                'has_children' => true,
            ]],
            topicCount: 1,
            totalArticles: 8,
            totalKeywords: 20,
            sourceUpdatedAt: '2026-09-22',
        );

        $service = (new ReflectionClass(TopicalMapAuditService::class))->newInstanceWithoutConstructor();
        $projection = $service->structuralProjection($overview);

        self::assertSame('topic:12', $projection['topics'][0]['topic_ref']);
        self::assertArrayNotHasKey('has_children', $projection['topics'][0]);
        self::assertArrayNotHasKey('id', $projection['topics'][0]);
        self::assertSame('School bags', $projection['topics'][0]['name']);
    }

    public function test_parser_accepts_valid_structured_result(): void
    {
        $parser = new TopicalMapAuditResultParser;
        $result = $parser->parse(json_encode([
            'summary' => 'Coverage uneven',
            'findings' => [[
                'type' => 'weak_coverage',
                'severity' => 'HIGH',
                'topic_ref' => 'topic:12',
                'topic_name' => 'School bags',
                'title' => 'Weak bags',
                'observation' => 'Low MCP with thin DNA',
                'evidence' => ['MCP 12%', 'DNA 1'],
            ]],
            'opportunities' => [[
                'topic_ref' => 'topic:12',
                'topic_name' => 'School bags',
                'title' => 'Expand DNA',
                'reason' => 'Demand signals',
                'suggested_direction' => 'Add supporting pages',
            ]],
            'recommended_actions' => [[
                'priority' => 1,
                'action_type' => 'expand_topic',
                'topic_ref' => 'topic:12',
                'title' => 'Expand School bags',
                'reason' => 'Weak coverage',
            ]],
        ], JSON_THROW_ON_ERROR), ['topic:12']);

        self::assertTrue($result['ok']);
        self::assertSame('Coverage uneven', $result['payload']['summary']);
        self::assertSame('high', $result['payload']['findings'][0]['severity']);
        self::assertSame('weak_coverage', $result['payload']['findings'][0]['type']);
        self::assertSame('topic:12', $result['payload']['findings'][0]['topic_ref']);
        self::assertSame(['MCP 12%', 'DNA 1'], $result['payload']['findings'][0]['evidence']);
        self::assertSame('Add supporting pages', $result['payload']['opportunities'][0]['suggested_direction']);
        self::assertSame('expand_topic', $result['payload']['recommended_actions'][0]['action_type']);
        self::assertArrayHasKey('tag_suggestions', $result['payload']);
    }

    public function test_parser_validates_tag_suggestions_refs_and_taxonomy_cap(): void
    {
        $parser = new TopicalMapAuditResultParser;
        $result = $parser->parse([
            'summary' => 'ok',
            'findings' => [],
            'opportunities' => [],
            'recommended_actions' => [['priority' => 1, 'action_type' => 'no_action', 'title' => 'hold']],
            'tag_suggestions' => [
                'taxonomy' => [
                    ['name' => 'B2B'],
                    ['name' => 'b2b'],
                    ['name' => 'OEM'],
                    ['name' => 'Ghost'],
                ],
                'assignments' => [
                    ['topic_ref' => 'topic:12', 'tags' => ['B2B', 'OEM', 'Unknown']],
                    ['topic_ref' => 'topic:999', 'tags' => ['B2B']],
                    ['topic_ref' => 'topic:12', 'tags' => ['OEM']],
                ],
            ],
        ], ['topic:12'], [
            ['name' => 'Existing', 'slug' => 'existing'],
        ]);

        self::assertTrue($result['ok']);
        $tags = $result['payload']['tag_suggestions'];
        $names = array_column($tags['taxonomy'], 'name');
        self::assertContains('B2B', $names);
        self::assertContains('OEM', $names);
        self::assertContains('Ghost', $names);
        self::assertCount(1, $tags['assignments']);
        self::assertSame('topic:12', $tags['assignments'][0]['topic_ref']);
        self::assertSame(12, $tags['assignments'][0]['topic_id']);
        self::assertContains('B2B', $tags['assignments'][0]['tags']);
        self::assertContains('OEM', $tags['assignments'][0]['tags']);
        self::assertNotContains('Unknown', $tags['assignments'][0]['tags']);
    }

    public function test_parser_rejects_invalid_type_severity_action_and_unknown_topic_ref(): void
    {
        $parser = new TopicalMapAuditResultParser;
        $result = $parser->parse([
            'summary' => 'ok',
            'findings' => [
                ['type' => 'made_up_type', 'severity' => 'high', 'title' => 'x', 'observation' => 'y'],
                ['type' => 'weak_coverage', 'severity' => 'critical', 'topic_ref' => 'topic:999', 'title' => 'A', 'observation' => 'B'],
                ['type' => 'internal_link_gap', 'severity' => 'low', 'topic_ref' => 'topic:12', 'title' => 'Links', 'observation' => 'No link data used'],
            ],
            'opportunities' => [
                ['topic_ref' => 'topic:999', 'title' => 'Ghost', 'reason' => 'x'],
            ],
            'recommended_actions' => [
                ['priority' => 1, 'action_type' => 'invent_topic', 'topic_ref' => 'topic:12', 'title' => 'Bad type'],
                ['priority' => 2, 'action_type' => 'review_structure', 'topic_ref' => 'topic:999', 'title' => 'Unknown ref'],
            ],
        ], ['topic:12']);

        self::assertTrue($result['ok']);
        self::assertCount(2, $result['payload']['findings']);
        self::assertNull($result['payload']['findings'][0]['topic_ref']);
        self::assertSame('medium', $result['payload']['findings'][0]['severity']);
        self::assertSame('topic:12', $result['payload']['findings'][1]['topic_ref']);
        self::assertNull($result['payload']['opportunities'][0]['topic_ref']);
        self::assertSame('no_action', $result['payload']['recommended_actions'][0]['action_type']);
        self::assertNull($result['payload']['recommended_actions'][1]['topic_ref']);
    }

    public function test_parser_handles_malformed_and_fenced_json(): void
    {
        $parser = new TopicalMapAuditResultParser;
        self::assertFalse($parser->parse('{}')['ok']);
        self::assertFalse($parser->parse('not-json')['ok']);

        $fenced = "```json\n{\"summary\":\"ok\",\"findings\":[],\"opportunities\":[],\"recommended_actions\":[{\"priority\":1,\"action_type\":\"no_action\",\"title\":\"a\"}]}\n```";
        $ok = $parser->parse($fenced);
        self::assertTrue($ok['ok']);
        self::assertSame('ok', $ok['payload']['summary']);
    }

    public function test_contracts_topic_ref_helpers(): void
    {
        self::assertSame('topic:5', TopicalMapAuditContracts::topicRef(5));
        self::assertSame(5, TopicalMapAuditContracts::topicIdFromRef('topic:5'));
        self::assertNull(TopicalMapAuditContracts::topicIdFromRef('5'));
        self::assertNull(TopicalMapAuditContracts::topicIdFromRef('topic:abc'));
        self::assertTrue(TopicalMapAuditContracts::isAllowedFindingType('coverage_gap'));
        self::assertFalse(TopicalMapAuditContracts::isAllowedFindingType('random'));
    }

    public function test_manual_audit_path_wires_history_linker_and_confirm_action(): void
    {
        // Topics page (KeywordTopicClusters) + React controller — not legacy KeywordTopicalMap redirect stub.
        $traitSrc = (string) file_get_contents((string) (new ReflectionClass(RunsTopicalMapAuditAndTags::class))->getFileName());
        self::assertStringContainsString('TopicalMapAuditHistoryLinker', $traitSrc);
        self::assertStringContainsString('beginConfirmAiAudit', $traitSrc);
        self::assertStringContainsString('confirmRunAiAuditAndTags', $traitSrc);
        self::assertStringContainsString('TopicalMapAuditService', $traitSrc);
        self::assertStringContainsString('keywordLanguageFilter', $traitSrc);
        self::assertStringContainsString('languageVariants', $traitSrc);

        $topicsPage = (string) file_get_contents((string) (new ReflectionClass(KeywordTopicClusters::class))->getFileName());
        self::assertStringContainsString('RunsTopicalMapAuditAndTags', $topicsPage);
        self::assertStringContainsString('resolveKeywordLanguageFilterVariants()', $topicsPage);

        $controller = (string) file_get_contents((string) (new ReflectionClass(TopicalMapAuditController::class))->getFileName());
        self::assertStringContainsString('TopicalMapAuditHistoryLinker', $controller);
        self::assertStringContainsString('TopicalMapAuditService', $controller);
        self::assertStringContainsString('prompt_result_id', $controller);

        $bladeAlt = dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php';
        self::assertFileExists($bladeAlt);
        $bladeSrc = (string) file_get_contents($bladeAlt);
        self::assertStringContainsString('beginConfirmAiAudit', $bladeSrc);
        self::assertStringContainsString('confirmRunAiAuditAndTags', $bladeSrc);
        self::assertStringContainsString('ai_audit_tags', $bladeSrc);
    }

    public function test_finding_and_action_enums_are_closed(): void
    {
        self::assertContains('internal_link_gap', TopicalMapAuditContracts::FINDING_TYPES);
        self::assertContains('investigate_new_topic', TopicalMapAuditContracts::ACTION_TYPES);
        self::assertSame(['low', 'medium', 'high'], TopicalMapAuditContracts::SEVERITIES);
    }
}
