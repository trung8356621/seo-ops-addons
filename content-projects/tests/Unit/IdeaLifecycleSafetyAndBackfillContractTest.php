<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateConsumptionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningDataBackfillService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningMonthBackfill;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ConsumeVocabularySuggestCandidateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contracts + pure behavior for schema gate, multi-site consume safety, chunked backfill.
 */
final class IdeaLifecycleSafetyAndBackfillContractTest extends TestCase
{
    public function test_claim_throws_schema_not_ready_not_duplicate(): void
    {
        self::assertSame('idea_consumption_schema_not_ready', IdeaCandidateConsumptionService::ERROR_SCHEMA_NOT_READY);

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateConsumptionService::class))->getFileName(),
        );
        self::assertStringContainsString('throw new RuntimeException(self::ERROR_SCHEMA_NOT_READY)', $src);
        self::assertStringContainsString("'site_id' => \$siteId", $src);
        self::assertStringContainsString("'source_ref' =>", $src);
        // Must not return claimed=false for missing schema (that would look like a duplicate).
        $schemaPos = strpos($src, 'if (! $this->tableReady())');
        $throwPos = strpos($src, 'throw new RuntimeException(self::ERROR_SCHEMA_NOT_READY)');
        self::assertNotFalse($schemaPos);
        self::assertNotFalse($throwPos);
        self::assertLessThan($throwPos, $schemaPos);
        $between = substr($src, $schemaPos, $throwPos - $schemaPos);
        self::assertStringNotContainsString("'claimed' => false", $between);
    }

    public function test_consume_service_guards_shared_classification(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ConsumeVocabularySuggestCandidateService::class))->getFileName(),
        );

        self::assertStringContainsString('isSharedWithOtherSites', $src);
        self::assertStringContainsString('clearClassificationIfOrphan', $src);
        self::assertStringContainsString('Keep global seo_keyword_classifications.cluster_key', $src);
        // Must check share BEFORE clearing classification.
        $sharePos = strpos($src, '$shared = $this->isSharedWithOtherSites');
        $clearPos = strpos($src, 'clearClassificationIfOrphan');
        self::assertNotFalse($sharePos);
        self::assertNotFalse($clearPos);
        self::assertLessThan($clearPos, $sharePos);
    }

    public function test_backfill_service_uses_keyset_chunks_and_month_ssot(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDataBackfillService::class))->getFileName(),
        );

        self::assertStringContainsString('PlanningMonthBackfill::resolve', $src);
        self::assertStringContainsString("where('t.id', '>', \$afterId)", $src);
        self::assertStringContainsString("where('o.id', '>', \$afterId)", $src);
        self::assertStringContainsString('orderBy', $src);
        self::assertStringContainsString('limit($chunkSize)', $src);
        self::assertStringNotContainsString('offset(', $src);
        self::assertStringNotContainsString('->limit(5000)', $src);
        self::assertSame(500, PlanningDataBackfillService::CHUNK);

        $migration300 = dirname(__DIR__, 2).'/database/migrations/2026_09_16_100300_backfill_consumed_ideas_and_planning_attribution.php';
        $migration400 = dirname(__DIR__, 2).'/database/migrations/2026_09_16_100400_repair_planning_backfill_beyond_5k.php';
        self::assertFileExists($migration300);
        self::assertFileExists($migration400);
        self::assertStringContainsString('PlanningDataBackfillService', (string) file_get_contents($migration300));
        self::assertStringContainsString('PlanningDataBackfillService', (string) file_get_contents($migration400));
        self::assertStringNotContainsString('->limit(5000)', (string) file_get_contents($migration300));
    }

    public function test_origin_project_id_uses_allocator_target(): void
    {
        $idea = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateDraftPlannerService::class
            ))->getFileName(),
        );
        self::assertStringContainsString('recordOrigin(', $idea);
        self::assertStringContainsString('$target', $idea);
        // Task payload and origin both bind to allocator target.
        self::assertMatchesRegularExpression("/'project_id'\\s*=>\\s*\\(int\\)\\s*\\\$target->getKey\\(\\)/", $idea);

        $ai = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService::class
            ))->getFileName(),
        );
        self::assertMatchesRegularExpression("/'project_id'\\s*=>\\s*\\(int\\)\\s*\\\$target->getKey\\(\\)/", $ai);
        self::assertStringContainsString('NewContentClusterAttributionValidator', $ai);
        self::assertStringContainsString('CODE_ATTRIBUTION_INVALID', $ai);
        self::assertStringContainsString('refusing to persist candidates with missing/invalid cluster_ref', $ai);
    }

    public function test_month_resolver_matches_migration_ssot(): void
    {
        $fixture = [
            'planning_month' => null,
            'created_at' => '2026-03-15',
            'target_date' => '2026-04-20',
            'project_month' => '2026-05-01',
            'project_is_draft' => true,
        ];
        self::assertSame('2026-03', PlanningMonthBackfill::resolve($fixture));

        $exec = $fixture;
        $exec['project_is_draft'] = false;
        self::assertSame('2026-05', PlanningMonthBackfill::resolve($exec));
    }
}
