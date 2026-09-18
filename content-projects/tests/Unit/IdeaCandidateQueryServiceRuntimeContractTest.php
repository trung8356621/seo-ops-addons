<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithIdeaCandidates;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateQueryService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIngestionPolicy;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\TestCase;

/**
 * Regression: SEO Audit Idea Candidate path must resolve Policy GROUP_* at runtime.
 * Prior contract tests only used ReflectionClass::getFileName() and missed ClassNotFound.
 */
final class IdeaCandidateQueryServiceRuntimeContractTest extends TestCase
{
    public function test_idea_candidate_query_service_instantiates_with_group_labels(): void
    {
        $service = new IdeaCandidateQueryService;

        $labels = (new ReflectionClass($service))->getConstant('GROUP_LABELS');
        self::assertIsArray($labels);
        self::assertArrayHasKey(VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS, $labels);
        self::assertArrayHasKey(VocabularyKeywordIngestionPolicy::GROUP_SALIENT_KEYWORDS, $labels);
        self::assertSame('Related topics', $labels[VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS]);
    }

    public function test_container_resolves_idea_candidate_query_service(): void
    {
        $service = app(IdeaCandidateQueryService::class);

        self::assertInstanceOf(IdeaCandidateQueryService::class, $service);

        $labels = (new ReflectionClass($service))->getConstant('GROUP_LABELS');
        self::assertIsArray($labels);
        self::assertNotEmpty($labels);
    }

    public function test_seo_audit_planner_idea_candidates_payload_path_resolves_without_class_not_found(): void
    {
        self::assertContains(
            InteractsWithIdeaCandidates::class,
            class_uses_recursive(ContentProjectSeoAuditPlanner::class),
        );

        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-draft-planner.blade.php'),
        );
        self::assertStringContainsString('ideaCandidatesPayload', $blade);

        // Exact failure site of HTTP 500: constructing QueryService evaluates GROUP_LABELS → Policy::GROUP_*.
        $service = app(IdeaCandidateQueryService::class);
        self::assertInstanceOf(IdeaCandidateQueryService::class, $service);
        self::assertTrue(class_exists(VocabularyKeywordIngestionPolicy::class));
    }
}
