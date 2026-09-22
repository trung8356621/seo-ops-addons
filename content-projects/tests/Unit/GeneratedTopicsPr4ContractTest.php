<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDiscoverNewTopics;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNotePromptSectionBuilder;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\GeneratedTopicMaterializer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentAutoDnaPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentClusterAttributionValidator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicPlanningRef;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * PR4 — Generated Topics → Generate Ideas (candidate_key) → atomic materialize on SplitDraft.
 */
final class GeneratedTopicsPr4ContractTest extends TestCase
{
    public function test_generated_note_items_merge_into_generate_options(): void
    {
        $src = LegacyAddonPath::read(
            (string) (new ReflectionClass(InteractsWithNewContentSuggestions::class))->getFileName(),
        );
        self::assertStringContainsString('mergedPlanningNoteItems', $src);
        self::assertStringContainsString('newTopicSelectedItems', $src);
        self::assertStringContainsString('applyMergedPlanningNoteItems', $src);
        self::assertStringContainsString('! AuditNoteDnaNormalizer::isGenerated($item)', $src);
    }

    public function test_existing_topic_shape_does_not_require_candidate_key(): void
    {
        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => 'cluster',
            'cluster_ref' => TopicPlanningRef::encode(42),
            'cluster_name_snapshot' => 'Existing Topic',
            'target_dna_count' => 5,
            'dna' => [],
        ]);
        self::assertNotNull($item);
        self::assertArrayNotHasKey('candidate_key', $item);
        self::assertSame('cluster', $item['source_type']);
    }

    public function test_generated_topic_input_carries_candidate_key_name_dna_target(): void
    {
        $item = AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate([
            'candidate_key' => 'generated-abc123',
            'name' => 'New Topic Alpha',
            'target_dna_count' => 7,
            'dna' => ['angle-a', 'angle-b'],
        ]);
        self::assertNotNull($item);
        self::assertSame(AuditNoteDnaNormalizer::SOURCE_TYPE_GENERATED, $item['source_type']);
        self::assertSame('generated-abc123', $item['candidate_key']);
        self::assertSame('generated:generated-abc123', $item['cluster_ref']);
        self::assertSame('New Topic Alpha', $item['cluster_name_snapshot']);
        self::assertSame(7, $item['target_dna_count']);
        self::assertCount(2, $item['dna']);
    }

    public function test_validator_stamps_candidate_key_and_blocks_cross_map(): void
    {
        $validator = new NewContentClusterAttributionValidator;
        $noteItems = [
            AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate([
                'candidate_key' => 'gen-a',
                'name' => 'A',
                'target_dna_count' => 3,
                'dna' => ['a'],
            ]),
            AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate([
                'candidate_key' => 'gen-b',
                'name' => 'B',
                'target_dna_count' => 3,
                'dna' => ['b'],
            ]),
        ];
        $gate = $validator->filter([
            [
                'keyword' => 'kw-a',
                'title' => 'Title A',
                'cluster_ref' => 'generated:gen-a',
                'dna_phrases' => ['a'],
            ],
            [
                'keyword' => 'kw-hijack',
                'title' => 'Hijack',
                'cluster_ref' => 'generated:gen-b',
                'dna_phrases' => ['stolen'],
            ],
            [
                'keyword' => 'kw-bad',
                'title' => 'Bad',
                'cluster_ref' => 'generated:invented',
                'dna_phrases' => ['x'],
            ],
        ], $noteItems);

        self::assertCount(2, $gate['accepted']);
        self::assertCount(1, $gate['rejected']);
        self::assertSame('gen-a', $gate['accepted'][0]['candidate_key']);
        self::assertSame('generated:gen-a', $gate['accepted'][0]['cluster_ref']);
        self::assertSame('gen-b', $gate['accepted'][1]['candidate_key']);
        self::assertSame('generated:gen-b', $gate['accepted'][1]['cluster_ref']);
    }

    public function test_rename_and_dna_edit_preserve_candidate_key(): void
    {
        $item = AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate([
            'candidate_key' => 'stable-key',
            'name' => 'Original',
            'target_dna_count' => 4,
            'dna' => ['one'],
        ]);
        self::assertNotNull($item);

        $renamed = AuditNoteDnaNormalizer::normalizeNoteItem([
            ...$item,
            'cluster_name_snapshot' => 'Renamed Topic',
            'dna' => [
                ['phrase' => 'one', 'slots' => 1, 'source' => 'manual'],
                ['phrase' => 'two', 'slots' => 1, 'source' => 'manual'],
            ],
        ]);
        self::assertNotNull($renamed);
        self::assertSame('stable-key', $renamed['candidate_key']);
        self::assertSame('generated:stable-key', $renamed['cluster_ref']);
        self::assertSame('Renamed Topic', $renamed['cluster_name_snapshot']);
        self::assertCount(2, $renamed['dna']);
    }

    public function test_prompt_and_policy_describe_generated_without_direct_provider(): void
    {
        $builder = new AuditNotePromptSectionBuilder;
        $lines = implode("\n", $builder->lines([
            AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate([
                'candidate_key' => 'g1',
                'name' => 'Candidate One',
                'target_dna_count' => 5,
                'dna' => ['dna-1'],
            ]),
        ]));
        self::assertStringContainsString('source_type=generated', $lines);
        self::assertStringContainsString('candidate_key=g1', $lines);

        $policy = implode("\n", (new NewContentAutoDnaPolicy)->instructionLines(5, [
            AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate([
                'candidate_key' => 'g1',
                'name' => 'Candidate One',
                'target_dna_count' => 5,
                'dna' => [],
            ]),
        ]));
        self::assertStringContainsString('source_type=generated', $policy);
        self::assertStringContainsString('candidate_key', $policy);

        $planner = LegacyAddonPath::read(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringNotContainsString('OpenAI::', $planner);
        self::assertStringNotContainsString('Http::post', $planner);
    }

    public function test_materializer_hooks_split_draft_and_reuses_topic_core(): void
    {
        $split = LegacyAddonPath::read(
            (string) (new ReflectionClass(SplitDraftContentProjectService::class))->getFileName(),
        );
        self::assertStringContainsString('GeneratedTopicMaterializer', $split);
        self::assertStringContainsString('materializeGeneratedTopicsForTasks', $split);
        self::assertStringContainsString('generated_topic_mapping', $split);

        $materializer = LegacyAddonPath::read(
            (string) (new ReflectionClass(GeneratedTopicMaterializer::class))->getFileName(),
        );
        self::assertStringContainsString('createExclusiveTopic', $materializer);
        self::assertStringContainsString('DiscoverNewTopicsDuplicateFilter', $materializer);
        self::assertStringContainsString('TopicPlanningRef::encode', $materializer);
        self::assertStringContainsString('rebuildForTopic', $materializer);
        self::assertStringContainsString('ownerProjectIds', $materializer);
        self::assertStringContainsString('whereIn(\'project_id\', $ownerProjectIds)', $materializer);
        self::assertStringContainsString('TopicSource::MANUAL', $materializer);
        self::assertStringNotContainsString('TopicManualCreateService', $materializer);

        $discover = LegacyAddonPath::read(
            (string) (new ReflectionClass(InteractsWithDiscoverNewTopics::class))->getFileName(),
        );
        self::assertStringContainsString('purgeGeneratedDraftIdeasForClusterRef', $discover);
        self::assertStringContainsString('consumeGeneratedTopicsAfterMaterialize', $discover);
    }

    public function test_alpine_snapshot_preserves_generated_source_type(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        self::assertStringContainsString('isGeneratedItem', $blade);
        self::assertStringContainsString('resolveSourceType', $blade);
        self::assertStringContainsString("'generated'", $blade);
        self::assertStringContainsString('candidate_key', $blade);
    }

    public function test_mixed_batch_attribution_supports_topic_id_and_candidate_key(): void
    {
        $validator = new NewContentClusterAttributionValidator;
        $noteItems = [
            [
                'source_type' => 'cluster',
                'cluster_ref' => TopicPlanningRef::encode(99),
                'cluster_name_snapshot' => 'Live',
                'target_dna_count' => 3,
                'dna' => [],
            ],
            AuditNoteDnaNormalizer::noteItemFromGeneratedCandidate([
                'candidate_key' => 'mix-1',
                'name' => 'Temp',
                'target_dna_count' => 3,
                'dna' => ['t'],
            ]),
        ];
        $gate = $validator->filter([
            ['keyword' => 'e', 'title' => 'E', 'cluster_ref' => TopicPlanningRef::encode(99), 'dna_phrases' => []],
            ['keyword' => 'g', 'title' => 'G', 'cluster_ref' => 'generated:mix-1', 'dna_phrases' => ['t']],
        ], $noteItems);

        self::assertCount(2, $gate['accepted']);
        self::assertSame(TopicPlanningRef::encode(99), $gate['accepted'][0]['cluster_ref']);
        self::assertArrayNotHasKey('candidate_key', $gate['accepted'][0]);
        self::assertSame('mix-1', $gate['accepted'][1]['candidate_key']);
    }

    public function test_materializer_public_api_surface(): void
    {
        $method = new ReflectionMethod(GeneratedTopicMaterializer::class, 'materializeForTasks');
        self::assertTrue($method->isPublic());
        self::assertSame(2, $method->getNumberOfParameters());
    }
}
