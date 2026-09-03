<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;

/**
 * Split Topic planning slots into model-safe batches (greedy, preserves Topic demand).
 */
final class NewContentPlanningSlotSplitter
{
    /**
     * @param  list<array<string, mixed>>  $noteItems
     * @return list<array{
     *   batch_index: int,
     *   requested: int,
     *   note_items: list<array<string, mixed>>
     * }>
     */
    public function split(array $noteItems, int $batchSize): array
    {
        $items = AuditNoteDnaNormalizer::normalizeNoteItems($noteItems);
        $batchSize = max(1, $batchSize);
        if ($items === []) {
            return [];
        }

        /** @var list<array{item: array<string, mixed>, remaining_target: int, remaining_dna: list<array{phrase: string, slots: int, source: string}>}> $queue */
        $queue = [];
        foreach ($items as $item) {
            $dna = is_array($item['dna'] ?? null) ? $item['dna'] : [];
            $queue[] = [
                'item' => $item,
                'remaining_target' => max(0, (int) $item['target_dna_count']),
                'remaining_dna' => array_map(
                    static fn (array $row): array => [
                        'phrase' => (string) $row['phrase'],
                        'slots' => (int) $row['slots'],
                        'source' => (string) $row['source'],
                    ],
                    $dna,
                ),
            ];
        }

        $batches = [];
        $batchIndex = 0;
        $currentItems = [];
        $currentFill = 0;

        $flush = static function () use (&$batches, &$batchIndex, &$currentItems, &$currentFill): void {
            if ($currentItems === []) {
                return;
            }
            $requested = 0;
            foreach ($currentItems as $row) {
                $requested += (int) $row['target_dna_count'];
            }
            $batches[] = [
                'batch_index' => $batchIndex,
                'requested' => $requested,
                'note_items' => $currentItems,
            ];
            $batchIndex++;
            $currentItems = [];
            $currentFill = 0;
        };

        foreach ($queue as $entry) {
            while ($entry['remaining_target'] > 0) {
                if ($currentFill >= $batchSize) {
                    $flush();
                }
                $capacity = $batchSize - $currentFill;
                $take = min($entry['remaining_target'], $capacity);
                [$takenDna, $entry['remaining_dna']] = $this->takeDnaSlots($entry['remaining_dna'], $take);

                $slice = $entry['item'];
                $slice['target_dna_count'] = $take;
                $slice['dna'] = $takenDna;
                // Keep specified floor coherent for this batch slice.
                $slice = AuditNoteDnaNormalizer::normalizeNoteItem($slice);
                if ($slice !== null) {
                    $currentItems[] = $slice;
                    $currentFill += (int) $slice['target_dna_count'];
                }
                $entry['remaining_target'] -= $take;
            }
        }
        $flush();

        return $batches;
    }

    /**
     * Prefer consuming specified DNA slots first within a Topic slice.
     *
     * @param  list<array{phrase: string, slots: int, source: string}>  $dna
     * @return array{0: list<array{phrase: string, slots: int, source: string}>, 1: list<array{phrase: string, slots: int, source: string}>}
     */
    private function takeDnaSlots(array $dna, int $take): array
    {
        if ($take <= 0 || $dna === []) {
            return [[], $dna];
        }

        $taken = [];
        $remaining = [];
        $left = $take;
        foreach ($dna as $row) {
            $slots = max(0, (int) $row['slots']);
            if ($left <= 0) {
                if ($slots > 0) {
                    $remaining[] = $row;
                }

                continue;
            }
            if ($slots <= $left) {
                $taken[] = $row;
                $left -= $slots;

                continue;
            }
            $taken[] = [
                'phrase' => $row['phrase'],
                'slots' => $left,
                'source' => $row['source'],
            ];
            $remaining[] = [
                'phrase' => $row['phrase'],
                'slots' => $slots - $left,
                'source' => $row['source'],
            ];
            $left = 0;
        }

        return [$taken, $remaining];
    }
}
