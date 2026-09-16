<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftSplit;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectConsumedIdea;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTaskPlanningAttribution;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateConsumptionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateDraftPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateQueryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningAttributionWriter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningMonthBackfill;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ConsumeVocabularySuggestCandidateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contracts: idea consumption tombstone, planning attribution, active month, Site Planning MCP formula.
 */
final class IdeaConsumptionAndSitePlanningLifecycleContractTest extends TestCase
{
    public function test_query_excludes_by_tombstone_not_draft_text(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateQueryService::class))->getFileName(),
        );

        self::assertStringContainsString('excludeConsumedVocabularyCandidates', $src);
        self::assertStringContainsString('seo_content_project_consumed_ideas', $src);
        self::assertStringNotContainsString('excludePlannedCreateDuplicates', $src);
        self::assertStringNotContainsString('LOWER(TRIM(phrase)) NOT IN', $src);
    }

    public function test_draft_planner_claims_tombstone_before_cleanup(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateDraftPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString('IdeaCandidateConsumptionService', $src);
        self::assertStringContainsString('->claim(', $src);
        self::assertStringContainsString('cleanupVocabularySuggestSource', $src);
        self::assertStringContainsString('PlanningAttributionWriter', $src);
        self::assertStringContainsString('planning_month', $src);
        self::assertStringContainsString('projectWithRemainingCapacity', $src);
        // Capacity must be checked before claim so failed create does not consume.
        $capacityPos = strpos($src, 'projectWithRemainingCapacity');
        $claimPos = strpos($src, '->claim(');
        self::assertNotFalse($capacityPos);
        self::assertNotFalse($claimPos);
        self::assertLessThan($claimPos, $capacityPos);
    }

    public function test_consume_vocabulary_service_is_search_intelligence_owned(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ConsumeVocabularySuggestCandidateService::class))->getFileName(),
        );

        self::assertStringContainsString('TYPE_SUGGEST', $src);
        self::assertStringContainsString('AI_GENERATED', $src);
        self::assertStringContainsString('detachKeywordFromSite', $src);
        self::assertStringContainsString('KeywordOrphanCleanup', $src);
        self::assertStringContainsString('shared_with_other_sites', $src);
        self::assertStringNotContainsString('TYPE_DRAFT', $src);
    }

    public function test_consumed_idea_unique_identity(): void
    {
        $migration = dirname(__DIR__, 2).'/database/migrations/2026_09_16_100000_create_seo_content_project_consumed_ideas_table.php';
        self::assertFileExists($migration);
        $src = (string) file_get_contents($migration);
        self::assertStringContainsString("unique(['site_id', 'source_type', 'source_ref']", $src);
        self::assertSame('seo_content_project_consumed_ideas', (new SeoContentProjectConsumedIdea)->getTable());
    }

    public function test_planning_attribution_schema_and_writer(): void
    {
        $migration = dirname(__DIR__, 2).'/database/migrations/2026_09_16_100100_create_seo_content_project_task_planning_attributions_table.php';
        self::assertFileExists($migration);
        $src = (string) file_get_contents($migration);
        self::assertStringContainsString('cluster_ref', $src);
        self::assertStringContainsString('dna_phrases', $src);
        self::assertStringContainsString('planning_month', $src);
        self::assertStringContainsString('scp_tpa_task_unique', $src);
        self::assertStringContainsString("['project_task_id']", $src);

        $writer = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningAttributionWriter::class))->getFileName(),
        );
        self::assertStringContainsString('allowed_cluster_refs', $writer);
        self::assertStringContainsString('STATUS_UNATTRIBUTED', $writer);
        self::assertSame('unattributed', SeoContentProjectTaskPlanningAttribution::STATUS_UNATTRIBUTED);
    }

    public function test_planning_month_backfill_is_deterministic(): void
    {
        self::assertSame('2026-03', PlanningMonthBackfill::resolve([
            'planning_month' => null,
            'created_at' => '2026-03-15 10:00:00',
            'target_date' => '2026-04-01',
            'project_is_draft' => true,
        ]));
        self::assertSame('2026-08', PlanningMonthBackfill::resolve([
            'planning_month' => null,
            'project_month' => '2026-08-01',
            'project_is_draft' => false,
            'created_at' => '2026-01-01',
        ]));
        self::assertSame('2026-10', PlanningMonthBackfill::resolve([
            'planning_month' => '2026-10',
            'created_at' => '2020-01-01',
        ]));
        self::assertSame('1970-01', PlanningMonthBackfill::resolve([
            'planning_month' => null,
            'created_at' => null,
            'target_date' => null,
            'project_is_draft' => true,
        ]));
    }

    public function test_active_month_url_and_split_ssot(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        self::assertStringContainsString("Url(as: 'month'", $page);
        self::assertStringContainsString('activeMonth', $page);
        self::assertStringContainsString("params['month']", $page);
        self::assertStringContainsString('resolvePlannerActiveMonth', $page);

        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDraftSplit::class))->getFileName(),
        );
        self::assertStringContainsString('resolveDraftSplitActiveMonth', $trait);
        self::assertStringNotContainsString(
            '$this->draftSplitTargetMonth = ContentProjectMonthContext::current();',
            $trait,
        );
        self::assertStringContainsString('resolveDraftSplitActiveMonth()', $trait);

        $bar = (string) file_get_contents(
            (string) (new ReflectionClass(\Omnichannel\Addons\Seo\Livewire\GlobalSeoBar::class))->getFileName(),
        );
        self::assertStringContainsString('plannerActiveMonth', $bar);
        self::assertStringContainsString('seo-planner-month-changed', $bar);
        self::assertStringContainsString('showPlannerActiveMonth', $bar);

        $barView = dirname((string) (new ReflectionClass(\Omnichannel\Addons\Seo\Livewire\GlobalSeoBar::class))->getFileName(), 3)
            .'/resources/views/livewire/global-seo-bar.blade.php';
        self::assertFileExists($barView);
        $barBlade = (string) file_get_contents($barView);
        self::assertStringContainsString('plannerActiveMonth', $barBlade);
        self::assertStringContainsString('data-planner-active-month', $barBlade);
    }

    public function test_site_planning_matrix_is_distribution_count(): void
    {
        $agg = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningActiveUnitAggregator::class))->getFileName(),
        );
        self::assertStringContainsString('STATUS_CANCELLED', $agg);
        self::assertStringContainsString('whereNull(\'t.deleted_at\')', $agg);
        self::assertStringNotContainsString('SitePlanningActiveUnitPredicate', $agg);
        self::assertStringNotContainsString('publish_published_at', $agg);

        $read = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningReadModel::class))->getFileName(),
        );
        self::assertStringContainsString('SitePlanningActiveUnitAggregator', $read);
        self::assertStringContainsString('activeMonth', $read);
        self::assertStringContainsString('cellDetail', $read);
        self::assertStringContainsString('TopicHistoryReadModel', $read);
        self::assertStringNotContainsString('ContentProjectMonthlyWorkloadService', $read);
    }

    public function test_double_count_avoided_by_task_id_map(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningActiveUnitAggregator::class))->getFileName(),
        );
        self::assertStringContainsString('$taskId', $src);
        self::assertStringContainsString('project_task_id', $src);
        self::assertStringContainsString('STATUS_CANCELLED', $src);
        self::assertStringNotContainsString('SitePlanningActiveUnitPredicate', $src);
    }

    public function test_parser_accepts_backward_compatible_cluster_fields(): void
    {
        $parser = new NewContentSuggestionParser;
        $parsed = $parser->parse([
            [
                'keyword' => 'balo học sinh',
                'suggested_title' => 'Balo học sinh 2026',
                'cluster_ref' => 'ck_balo',
                'dna_phrases' => ['chất liệu', 'size'],
            ],
        ], 5);

        self::assertCount(1, $parsed['candidates']);
        self::assertSame('ck_balo', $parsed['candidates'][0]['cluster_ref']);
        self::assertSame(['chất liệu', 'size'], $parsed['candidates'][0]['dna_phrases']);

        $legacy = $parser->parse([['keyword' => 'legacy only']], 1);
        self::assertSame('', $legacy['candidates'][0]['cluster_ref']);
        self::assertSame([], $legacy['candidates'][0]['dna_phrases']);
    }

    public function test_month_context_normalize_for_url(): void
    {
        self::assertSame('2026-10', ContentProjectMonthContext::normalize('2026-10'));
        self::assertSame('2026-10', ContentProjectMonthContext::normalize('2026-10-01'));
        self::assertSame('10/2026', ContentProjectMonthContext::display('2026-10'));
        self::assertNull(ContentProjectMonthContext::parseOrNull('bad'));
    }

    public function test_consumption_service_unique_violation_is_idempotent_claim(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateConsumptionService::class))->getFileName(),
        );
        self::assertStringContainsString('isUniqueViolation', $src);
        self::assertStringContainsString("'claimed' => false", $src);
        self::assertStringContainsString('cleanupVocabularySuggestSource', $src);
        self::assertStringContainsString(IdeaCandidateConsumptionService::ERROR_SCHEMA_NOT_READY, $src);
        self::assertStringContainsString('throw new RuntimeException', $src);
        self::assertStringContainsString(IdeaCandidateConsumptionService::STATUS_ALREADY_CONSUMED, $src);
        self::assertStringContainsString(IdeaCandidateConsumptionService::STATUS_CLAIMED, $src);
        self::assertStringContainsString("'status' =>", $src);
        self::assertStringContainsString("'reason' =>", $src);
    }
}
