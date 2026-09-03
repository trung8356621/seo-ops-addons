<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNotePromptSectionBuilder;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentAutoDnaPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Target DNA + slots model — system-owned fill policy, not Prompt Management.
 */
final class NewContentAutoDnaPolicyTest extends TestCase
{
    public function test_legacy_weight_migrates_to_slots_one_not_weight_value(): void
    {
        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'cluster_ref' => 'clu_a',
            'cluster_name_snapshot' => 'A',
            'mcp_share_snapshot' => 1.2,
            'dna' => [['phrase' => 'quà tặng', 'weight' => 10, 'source' => 'cluster']],
        ]);
        self::assertNotNull($item);
        self::assertSame(1, $item['dna'][0]['slots']);
        self::assertSame(5, $item['target_dna_count']);
        self::assertArrayNotHasKey('dna_mode', $item);
        self::assertArrayNotHasKey('topic_weight', $item);
        self::assertArrayNotHasKey('weight', $item['dna'][0]);
    }

    public function test_duplicate_demand_survives_as_slots(): void
    {
        $dna = AuditNoteDnaNormalizer::addDna([], 'quà tặng');
        $dna = AuditNoteDnaNormalizer::duplicateDna($dna, 'quà tặng');
        self::assertCount(1, $dna);
        self::assertSame(2, $dna[0]['slots']);

        $dna = AuditNoteDnaNormalizer::removeDnaSlot($dna, 'quà tặng');
        self::assertSame(1, $dna[0]['slots']);
        $dna = AuditNoteDnaNormalizer::removeDnaSlot($dna, 'quà tặng');
        self::assertSame([], $dna);
    }

    public function test_target_auto_increases_when_specified_exceeds_but_delete_does_not_decrease(): void
    {
        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'cluster_ref' => 'clu_b',
            'cluster_name_snapshot' => 'B',
            'target_dna_count' => 5,
            'dna' => [
                ['phrase' => 'a', 'slots' => 1],
                ['phrase' => 'b', 'slots' => 1],
                ['phrase' => 'c', 'slots' => 1],
                ['phrase' => 'd', 'slots' => 1],
                ['phrase' => 'e', 'slots' => 1],
            ],
        ]);
        self::assertNotNull($item);
        self::assertSame(5, $item['target_dna_count']);

        $dna = AuditNoteDnaNormalizer::duplicateDna($item['dna'], 'a');
        $target = AuditNoteDnaNormalizer::ensureTargetCoversSpecified(5, $dna);
        self::assertSame(6, $target);
        self::assertSame(6, AuditNoteDnaNormalizer::specifiedSlotCount($dna));

        $dna = AuditNoteDnaNormalizer::removeDnaSlot($dna, 'b');
        self::assertSame(5, AuditNoteDnaNormalizer::specifiedSlotCount($dna));
        // Caller keeps prior target=6; delete must not force reduce.
        self::assertSame(6, AuditNoteDnaNormalizer::ensureTargetCoversSpecified(6, $dna));
        self::assertSame(1, AuditNoteDnaNormalizer::missingSlotCount(6, $dna));
    }

    public function test_prompt_section_serializes_target_and_slots(): void
    {
        $lines = (new AuditNotePromptSectionBuilder)->lines([[
            'cluster_ref' => 'clu_auto',
            'cluster_name_snapshot' => 'Balo Laptop',
            'mcp_share_snapshot' => 0,
            'target_dna_count' => 10,
            'dna' => [
                ['phrase' => 'quà tặng', 'slots' => 2, 'source' => 'manual'],
                ['phrase' => 'đồng phục', 'slots' => 1, 'source' => 'cluster'],
                ['phrase' => 'sinh viên', 'slots' => 1, 'source' => 'manual'],
                ['phrase' => 'chống nước', 'slots' => 1, 'source' => 'manual'],
            ],
        ]]);
        $joined = implode("\n", $lines);
        self::assertStringContainsString('Target DNA: 10', $joined);
        self::assertStringContainsString('Specified slots: 5', $joined);
        self::assertStringContainsString('Missing slots: 5', $joined);
        self::assertStringContainsString('quà tặng · placement=after ×2', $joined);
        self::assertStringNotContainsString('SYSTEM AUTOMATION POLICY', $joined);
        self::assertStringNotContainsString('AUTO DNA', $joined);
        self::assertStringNotContainsString('topic weight', $joined);
    }

    public function test_policy_emits_missing_slots_independent_of_user_prompt(): void
    {
        $policy = new NewContentAutoDnaPolicy;
        self::assertSame(3, NewContentAutoDnaPolicy::VERSION);

        $noteItems = [[
            'cluster_ref' => 'clu_a',
            'cluster_name_snapshot' => 'Balo Laptop',
            'target_dna_count' => 10,
            'dna' => [
                ['phrase' => 'quà tặng', 'slots' => 2],
                ['phrase' => 'đồng phục', 'slots' => 1],
                ['phrase' => 'sinh viên', 'slots' => 1],
                ['phrase' => 'chống nước', 'slots' => 1],
            ],
        ]];
        $meta = $policy->metadata(20, $noteItems);
        self::assertSame(3, $meta['auto_dna_version']);
        self::assertSame(5, $meta['total_missing_slots']);
        self::assertSame(10, $meta['total_topic_target']);

        $rules = implode("\n", $policy->instructionLines(20, $noteItems));
        self::assertStringContainsString('SYSTEM AUTOMATION POLICY', $rules);
        self::assertStringContainsString('missing_slots=5', $rules);
        self::assertStringContainsString('Repeated phrase slots', $rules);

        $svc = new ContentPlanningIntelligenceService;
        $ctx = [
            'site' => ['id' => 1, 'domain' => 'example.com', 'primary_language' => 'vi'],
            'coverage' => ['covered' => 0, 'weakly_covered' => 0, 'uncovered' => 0, 'unknown' => 0],
            'principal_keywords' => [],
            'clusters' => [],
            'missing_directions' => [],
            'existing_topics' => [],
            'planned_topics' => [],
            'rejected_topics' => [],
            'mcp_signals' => [],
            'gsc_signals' => [],
            'mcp_period' => null,
            'covered_keyword_norms' => [],
            'planned_fingerprints' => [],
            'rejected_fingerprints' => [],
            'diagnostics' => [
                'principal_keywords_count' => 0,
                'cluster_count' => 0,
                'missing_direction_count' => 0,
                'mcp_period' => null,
                'covered_keyword_count' => 0,
            ],
        ];
        $brief = $svc->renderBrief($ctx, [
            'quantity' => 20,
            'notes' => 'Write discovery ideas in a friendly tone only.',
            'note_items' => $noteItems,
        ]);
        self::assertStringContainsString('SYSTEM AUTOMATION POLICY', $brief);
        self::assertStringContainsString('auto_dna v3', $brief);
        self::assertStringContainsString('Write discovery ideas in a friendly tone only.', $brief);
        self::assertStringContainsString('Missing slots: 5', $brief);
    }

    public function test_options_snapshot_preserves_target_slots_and_policy_version(): void
    {
        $opts = NewContentSuggestionOptions::normalize([
            'quantity' => 20,
            'note_items' => [[
                'cluster_ref' => 'clu_a',
                'cluster_name_snapshot' => 'A',
                'target_dna_count' => 10,
                'dna' => [['phrase' => 'a', 'slots' => 2, 'source' => 'manual']],
            ]],
        ]);
        self::assertSame(10, $opts['note_items'][0]['target_dna_count']);
        self::assertSame(2, $opts['note_items'][0]['dna'][0]['slots']);

        $snap = NewContentSuggestionOptions::snapshot($opts, 'vi', 9);
        self::assertSame(10, $snap['note_items'][0]['target_dna_count']);
        self::assertSame(3, $snap['automation_policy']['auto_dna_version']);
        self::assertSame(10, $snap['automation_policy']['total_topic_target']);
        self::assertSame(8, $snap['automation_policy']['total_missing_slots']);
    }

    public function test_total_target_helper_for_generate_gate(): void
    {
        $total = AuditNoteDnaNormalizer::totalTargetDnaCount([
            ['cluster_ref' => 'a', 'cluster_name_snapshot' => 'A', 'target_dna_count' => 10, 'dna' => []],
            ['cluster_ref' => 'b', 'cluster_name_snapshot' => 'B', 'target_dna_count' => 13, 'dna' => []],
        ]);
        self::assertSame(23, $total);
    }

    public function test_planner_and_generate_wiring(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringContainsString('NewContentAutoDnaPolicy', $src);
        self::assertStringContainsString("'automation_policy'", $src);

        $method = new ReflectionMethod(NewContentSuggestionPlannerService::class, 'runDiscoveryPrompt');
        self::assertGreaterThanOrEqual(9, $method->getNumberOfParameters());

        $gen = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('generateNewContentSuggestions(?array $noteItems = null)', $gen);
        self::assertStringContainsString('AuditNoteTargetAllocator::apply', $gen);
        self::assertStringContainsString('AuditNoteDnaNormalizer::totalTargetDnaCount', $gen);
        self::assertStringContainsString('NewContentGenerationBatchPolicy', $gen);
        self::assertStringContainsString('planner_plan_too_large', $gen);
        self::assertStringContainsString('audit_notes_too_many_topics', $gen);
        self::assertStringNotContainsString('audit_notes_target_exceeds_quantity', $gen);

        self::assertStringContainsString('NewContentPlanningSlotSplitter', $src);
        self::assertStringContainsString('NewContentCrossBatchContinuationPolicy', $src);
        self::assertStringContainsString('markProgress', $src);
    }
}
