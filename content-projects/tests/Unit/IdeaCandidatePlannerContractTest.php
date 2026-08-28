<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithIdeaCandidates;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddIdeaCandidatesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\AddIdeaCandidatesHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\DismissVocabularySuggestCandidateService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidate;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateDraftPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateQueryService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateSource;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates\IdeaCandidateSourceCatalog;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\VocabularySuggestStagingQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Contract: Project Planner Idea Candidate picker (Vocabulary Suggest → Draft, no AI).
 */
final class IdeaCandidatePlannerContractTest extends TestCase
{
    public function test_source_catalog_exposes_vocabulary_suggest_only(): void
    {
        $options = (new IdeaCandidateSourceCatalog)->options();
        self::assertCount(1, $options);
        self::assertSame(IdeaCandidateSource::KEY_VOCABULARY_SUGGEST, $options[0]['key']);
        self::assertSame('vocabulary_suggest', IdeaCandidateSource::KEY_VOCABULARY_SUGGEST);
    }

    public function test_query_service_uses_canonical_staging_pool(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateQueryService::class))->getFileName(),
        );

        self::assertStringContainsString('VocabularySuggestStagingQuery::forSite', $src);
        self::assertStringContainsString('TYPE_SUGGEST', (string) file_get_contents(
            (string) (new ReflectionClass(VocabularySuggestStagingQuery::class))->getFileName(),
        ));
        self::assertStringContainsString('AI_GENERATED', (string) file_get_contents(
            (string) (new ReflectionClass(VocabularySuggestStagingQuery::class))->getFileName(),
        ));
        self::assertStringContainsString('exclude_draft_duplicates', $src);
        self::assertStringContainsString('SeoHidden', $src);
        self::assertStringContainsString('PER_PAGE_DEFAULT = 20', $src);
        self::assertStringNotContainsString('PromptResult', $src);
        self::assertStringNotContainsString('seo_article_keywords', $src);
        self::assertStringNotContainsString('GenerateNewContent', $src);
    }

    public function test_draft_planner_create_path_has_no_ai(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaCandidateDraftPlannerService::class))->getFileName(),
        );

        self::assertStringContainsString('SOURCE_VOCABULARY_SUGGEST', $src);
        self::assertStringContainsString('TYPE_CREATE', $src);
        self::assertStringContainsString('plannedCreateKeywordNorms', $src);
        self::assertStringContainsString('SeoAuditSuggestionPlannerService', $src);
        self::assertStringNotContainsString('PromptRun', $src);
        self::assertStringNotContainsString('AiRoute', $src);
        self::assertStringNotContainsString('LLM', $src);
        self::assertSame('vocabulary_suggest', SeoContentProjectItemOrigin::SOURCE_VOCABULARY_SUGGEST);
    }

    public function test_command_bus_registers_add_idea_candidates(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName(),
        );

        self::assertStringContainsString('AddIdeaCandidatesCommand::class', $src);
        self::assertStringContainsString('AddIdeaCandidatesHandler::class', $src);
        self::assertSame('idea_candidates.added', ContentProjectActionCodes::IDEA_CANDIDATES_ADDED);
        self::assertSame('content_project.add_idea_candidates', (new AddIdeaCandidatesCommand(1, [1]))->name());
    }

    public function test_handler_delegates_to_draft_planner_without_ai(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AddIdeaCandidatesHandler::class))->getFileName(),
        );

        self::assertStringContainsString('IdeaCandidateDraftPlannerService', $src);
        self::assertStringContainsString('IDEA_CANDIDATES_ADDED', $src);
        self::assertStringNotContainsString('GenerateNewContent', $src);
    }

    public function test_livewire_concern_and_planner_page_wire_picker(): void
    {
        $concern = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithIdeaCandidates::class))->getFileName(),
        );
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-draft-planner.blade.php'),
        );
        $picker = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-idea-candidate-picker.blade.php'),
        );

        self::assertStringContainsString('InteractsWithIdeaCandidates', $page);
        self::assertStringContainsString('mountInteractsWithIdeaCandidates', $page);
        self::assertStringContainsString('addIdeaCandidatesAsCreate', $concern);
        self::assertStringContainsString('AddIdeaCandidatesCommand', $concern);
        self::assertStringNotContainsString('openIdeaRewritePicker', $picker);
        self::assertStringNotContainsString('openIdeaImprovePicker', $picker);
        self::assertStringNotContainsString('data-idea-action="rewrite"', $picker);
        self::assertStringNotContainsString('data-idea-action="improve"', $picker);
        self::assertStringContainsString('data-idea-action="create"', $picker);
        self::assertStringNotContainsString('generateNewContentSuggestions', $concern);
        self::assertStringContainsString('content-project-idea-candidate-picker', $blade);
        self::assertStringContainsString('data-idea-candidate-picker', $picker);
        self::assertStringContainsString('idea_candidate_heading', $picker);
        self::assertStringContainsString('cp-plan-card__scroll', $blade);
        self::assertStringNotContainsString('TYPE_SUGGEST', $picker);
        self::assertStringNotContainsString('ai_generated', $picker);
    }

    public function test_dismiss_service_targets_suggest_staging_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(DismissVocabularySuggestCandidateService::class))->getFileName(),
        );

        self::assertStringContainsString('HideKeywordFromSeoService', $src);
        self::assertStringContainsString('TYPE_SUGGEST', $src);
        self::assertStringContainsString('AI_GENERATED', $src);
        self::assertStringContainsString('VocabularySuggestStagingQuery', $src);
        self::assertStringNotContainsString('Keyword::TYPE_NORMAL', $src);
        self::assertStringNotContainsString('seo_article_keywords', $src);
    }

    public function test_livewire_dismiss_wires_delete_action(): void
    {
        $concern = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithIdeaCandidates::class))->getFileName(),
        );
        $picker = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-idea-candidate-picker.blade.php'),
        );

        self::assertStringContainsString('dismissIdeaCandidate', $concern);
        self::assertStringContainsString('DismissVocabularySuggestCandidateService', $concern);
        self::assertStringContainsString('dismissIdeaCandidate', $picker);
        self::assertStringContainsString('wire:confirm', $picker);
        self::assertStringContainsString('cp-idea-row__delete', $picker);
        self::assertStringContainsString('cp-plan-card__scroll', $picker);
    }

    public function test_candidate_ref_format(): void
    {
        $ref = IdeaCandidate::ref(IdeaCandidateSource::KEY_VOCABULARY_SUGGEST, 42);
        self::assertSame('vocabulary_suggest:42', $ref);

        $dto = new IdeaCandidate(
            candidateRef: $ref,
            keywordId: 42,
            phrase: 'Cách giặt balo phao',
            source: IdeaCandidateSource::KEY_VOCABULARY_SUGGEST,
            sourceLabel: 'Vocabulary Suggest',
            sourceArticleId: 7,
            sourceArticleTitle: 'Balo Vải Bố',
            vocabularyGroup: 'related_topics',
            vocabularyGroupLabel: 'Related topics',
        );

        $arr = $dto->toArray();
        self::assertSame(42, $arr['keyword_id']);
        self::assertSame('Vocabulary Suggest', $arr['source_label']);
        self::assertArrayNotHasKey('type', $arr);
    }
}
