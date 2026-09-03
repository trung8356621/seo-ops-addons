<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentCrossBatchContinuationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationBatchPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentPlanningSlotSplitter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

final class NewContentGenerationBatchingTest extends TestCase
{
    public function test_total_demand_is_sum_of_topic_targets(): void
    {
        $items = [
            $this->topic('A', 10),
            $this->topic('B', 6),
            $this->topic('C', 8),
        ];
        self::assertSame(24, AuditNoteDnaNormalizer::totalTargetDnaCount($items));
    }

    public function test_options_quantity_ceiling_matches_batch_policy(): void
    {
        self::assertSame(
            NewContentGenerationBatchPolicy::MAX_TOTAL_PLANNER_IDEAS,
            NewContentSuggestionOptions::MAX_QUANTITY,
        );
        $opts = NewContentSuggestionOptions::normalize(['quantity' => 500]);
        self::assertSame(200, $opts['quantity']);
        self::assertTrue((new NewContentGenerationBatchPolicy)->exceedsHardCeiling(201));
        self::assertFalse((new NewContentGenerationBatchPolicy)->exceedsHardCeiling(200));
    }

    public function test_free_policy_splits_42_into_safe_batches_of_10(): void
    {
        $items = [
            $this->topic('A', 10),
            $this->topic('B', 6),
            $this->topic('C', 18),
            $this->topic('D', 8),
        ];
        $batches = (new NewContentPlanningSlotSplitter)->split($items, NewContentGenerationBatchPolicy::BATCH_FREE_OR_WEAK);
        self::assertCount(5, $batches);
        self::assertSame(42, array_sum(array_column($batches, 'requested')));
        foreach ($batches as $batch) {
            self::assertLessThanOrEqual(10, (int) $batch['requested']);
        }
    }

    public function test_paid_policy_splits_42_and_preserves_topic_c_across_batches(): void
    {
        $items = [
            $this->topic('A', 10),
            $this->topic('B', 6),
            $this->topic('C', 18),
            $this->topic('D', 8),
        ];
        $batches = (new NewContentPlanningSlotSplitter)->split($items, NewContentGenerationBatchPolicy::BATCH_PAID_STANDARD);
        self::assertCount(3, $batches);
        self::assertSame(20, $batches[0]['requested']);
        self::assertSame(20, $batches[1]['requested']);
        self::assertSame(2, $batches[2]['requested']);

        $cSlots = 0;
        foreach ($batches as $batch) {
            foreach ($batch['note_items'] as $item) {
                if (($item['cluster_ref'] ?? '') === 'C') {
                    $cSlots += (int) $item['target_dna_count'];
                }
            }
        }
        self::assertSame(18, $cSlots);

        self::assertSame(['A', 'B', 'C'], array_column($batches[0]['note_items'], 'cluster_ref'));
        self::assertSame(4, (int) $batches[0]['note_items'][2]['target_dna_count']);
        self::assertSame(['C', 'D'], array_column($batches[1]['note_items'], 'cluster_ref'));
        self::assertSame(14, (int) $batches[1]['note_items'][0]['target_dna_count']);
        self::assertSame(6, (int) $batches[1]['note_items'][1]['target_dna_count']);
        self::assertSame(['D'], array_column($batches[2]['note_items'], 'cluster_ref'));
        self::assertSame(2, (int) $batches[2]['note_items'][0]['target_dna_count']);
    }

    public function test_partial_acceptance_keeps_remaining_demand_tracked(): void
    {
        $remaining = [
            $this->topic('A', 10, [['phrase' => 'p1', 'slots' => 3, 'source' => 'manual']]),
        ];
        $batchSlice = [
            $this->topic('A', 10, [['phrase' => 'p1', 'slots' => 3, 'source' => 'manual']]),
        ];

        $method = new ReflectionMethod(NewContentSuggestionPlannerService::class, 'reduceRemainingDemand');
        $method->setAccessible(true);
        $planner = (new ReflectionClass(NewContentSuggestionPlannerService::class))->newInstanceWithoutConstructor();

        $next = $method->invoke($planner, $remaining, $batchSlice, 8);
        self::assertCount(1, $next);
        self::assertSame(2, (int) $next[0]['target_dna_count']);
        self::assertSame(0, AuditNoteDnaNormalizer::specifiedSlotCount($next[0]['dna']));
    }

    public function test_cross_batch_policy_emits_compact_fingerprints_not_full_payloads(): void
    {
        $policy = new NewContentCrossBatchContinuationPolicy;
        $compact = $policy->compactAccepted([
            [
                'keyword' => 'balo hoc sinh',
                'title' => 'Cach chon balo',
                'fingerprint' => 'abc',
                'source_signal' => 'A',
                'description' => str_repeat('x', 500),
            ],
        ]);
        self::assertSame('balo hoc sinh', $compact[0]['keyword']);
        self::assertArrayNotHasKey('description', $compact[0]);
        $lines = $policy->instructionLines($compact);
        self::assertNotEmpty($lines);
        self::assertStringContainsString('cross_batch_continuation', $lines[0]);
        self::assertStringContainsString('balo hoc sinh', implode("\n", $lines));
    }

    public function test_ui_contract_hides_quantity_and_batch_mechanics(): void
    {
        $compat = ProjectRoot::addonsPath().DIRECTORY_SEPARATOR.'seo-content-ai-compat';
        $card = file_get_contents($compat.'/resources/views/components/content-project-new-content-card.blade.php');
        self::assertIsString($card);
        self::assertStringNotContainsString('wire:model="newContentQuantity"', $card);
        self::assertStringNotContainsString('planner_quantity', $card);
        self::assertStringNotContainsString('data-planner-ideas-total="1"', $card);
        self::assertStringContainsString('planner_generating_ideas', $card);
        self::assertStringNotContainsString('batch_size', $card);
        self::assertStringNotContainsString('3 AI runs', $card);

        $notes = file_get_contents($compat.'/resources/views/components/content-project-audit-notes.blade.php');
        self::assertIsString($notes);
        self::assertStringNotContainsString("\$watch('newContentQuantity'", $notes);
        self::assertStringContainsString('stickyTotal()', $notes);
        self::assertStringContainsString('planner_ideas_total', $notes);
        self::assertStringContainsString('cp-ai-topic-workspace', $notes);
    }

    /**
     * @param  list<array{phrase: string, slots: int, source: string}>  $dna
     * @return array<string, mixed>
     */
    private function topic(string $ref, int $target, array $dna = []): array
    {
        return [
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => $ref,
            'mcp_share_snapshot' => 1.0,
            'target_dna_count' => $target,
            'target_mode' => 'manual',
            'dna' => $dna,
        ];
    }
}
