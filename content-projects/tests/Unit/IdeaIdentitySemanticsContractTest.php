<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateDraftPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateQueryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionIdentity;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Identity / consumption semantics: provenance decides identity, not text similarity.
 *
 * | Source/type        | Identity                         | Consumed when              | Can reappear?                          |
 * | Vocabulary CREATE  | site+vocabulary_suggest+kw_id    | CREATE → Draft succeeds    | No (tombstone)                         |
 * | Vocabulary REWRITE | article task (seo_audit path)    | Never (no vocab tombstone) | Yes — phrase stays Available Ideas     |
 * | Vocabulary IMPROVE | article task (seo_audit path)    | Never                      | Yes                                    |
 * | AI New Content     | fingerprint (keyword+title) + origin source_type | Accept/plan / reject fp | Within AI namespace only; not vocab tombstone |
 */
final class IdeaIdentitySemanticsContractTest extends TestCase
{
    public function test_create_claims_tombstone_rewrite_improve_do_not(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateDraftPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString('ACTION_CREATE', $src);
        self::assertStringContainsString('ACTION_REWRITE', $src);
        self::assertStringContainsString('ACTION_IMPROVE', $src);
        self::assertStringContainsString('->claim(', $src);
        self::assertStringContainsString('addRewriteOrImprove', $src);
        self::assertStringContainsString('SeoAuditSuggestionPlannerService', $src);

        // claim() only in CREATE path (addCreateItems), not in addRewriteOrImprove body.
        $rewriteStart = strpos($src, 'function addRewriteOrImprove');
        self::assertNotFalse($rewriteStart);
        $rewriteBody = substr($src, $rewriteStart);
        self::assertStringNotContainsString('->claim(', $rewriteBody);
        self::assertStringContainsString('seoAuditPlanner->addToDraftProject', $rewriteBody);
    }

    public function test_available_ideas_exclude_only_vocabulary_tombstones(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateQueryService::class))->getFileName(),
        );

        self::assertStringContainsString('excludeConsumedVocabularyCandidates', $src);
        self::assertStringContainsString('KEY_VOCABULARY_SUGGEST', $src);
        self::assertStringNotContainsString('SOURCE_AI_NEW_CONTENT', $src);
    }

    public function test_ai_new_content_uses_fingerprint_namespace_not_vocab_tombstone(): void
    {
        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        $identity = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionIdentity::class))->getFileName(),
        );

        self::assertSame('ai_new_content', SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT);
        self::assertSame('vocabulary_suggest', SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST);
        self::assertNotSame(
            SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT,
            SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST,
        );
        self::assertStringContainsString('SOURCE_AI_NEW_CONTENT', $planner);
        self::assertStringContainsString('source_fingerprint', $planner);
        self::assertStringNotContainsString('IdeaCandidateConsumptionService', $planner);
        self::assertStringContainsString('function fingerprint', $identity);

        $sameTextDifferentSources = NewContentSuggestionIdentity::fingerprint('same phrase', 'Title A');
        self::assertNotSame('', $sameTextDifferentSources);
        // Text alone is not a cross-source identity key — namespaces stay separate.
        self::assertStringNotContainsString('vocabulary_suggest', $identity);
    }

    public function test_site_planning_dedupes_by_project_task_not_phrase_text(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningActiveUnitAggregator::class))->getFileName(),
        );

        self::assertStringContainsString('project_task_id', $src);
        self::assertStringNotContainsString('LOWER(TRIM(phrase))', $src);
        self::assertStringNotContainsString('mb_strtolower($phrase', $src);
    }

    public function test_rewrite_improve_doc_states_no_tombstone_by_design(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateDraftPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString('Does NOT claim vocabulary tombstone', $src);
        self::assertStringContainsString('may be repeated', $src);
    }
}
