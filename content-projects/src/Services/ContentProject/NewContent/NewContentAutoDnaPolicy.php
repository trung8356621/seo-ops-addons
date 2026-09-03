<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;

/**
 * System-owned DNA target fill policy.
 *
 * Product invariants for Topic target_dna_count / specified slots — NOT Prompt Management content.
 * Injected at runtime into discovery execution; never persisted into SeoPrompt.
 */
final class NewContentAutoDnaPolicy
{
    public const VERSION = 3;

    public const KEY = 'auto_dna';

    /**
     * @return array{
     *   key: string,
     *   auto_dna_version: int,
     *   requested_quantity: int,
     *   total_topic_target: int,
     *   total_specified_slots: int,
     *   total_missing_slots: int,
     *   cluster_count: int,
     *   manual_seed_count: int
     * }
     */
    public function metadata(int $requestedQuantity, array $noteItems = []): array
    {
        $items = AuditNoteDnaNormalizer::normalizeNoteItems($noteItems);
        $totalTarget = 0;
        $totalSpecified = 0;
        $totalMissing = 0;
        $clusterCount = 0;
        $seedCount = 0;
        foreach ($items as $item) {
            $target = (int) $item['target_dna_count'];
            $specified = AuditNoteDnaNormalizer::specifiedSlotCount($item['dna']);
            $totalTarget += $target;
            $totalSpecified += $specified;
            $totalMissing += AuditNoteDnaNormalizer::missingSlotCount($target, $item['dna']);
            if (AuditNoteDnaNormalizer::isManualSeed($item)) {
                $seedCount++;
            } else {
                $clusterCount++;
            }
        }

        return [
            'key' => self::KEY,
            'auto_dna_version' => self::VERSION,
            'requested_quantity' => max(1, $requestedQuantity),
            'total_topic_target' => $totalTarget,
            'total_specified_slots' => $totalSpecified,
            'total_missing_slots' => $totalMissing,
            'cluster_count' => $clusterCount,
            'manual_seed_count' => $seedCount,
        ];
    }

    /**
     * Compact system rules emitted once per discovery brief (not per Topic).
     *
     * @param  list<array<string, mixed>>  $noteItems
     * @return list<string>
     */
    public function instructionLines(int $requestedQuantity, array $noteItems): array
    {
        $items = AuditNoteDnaNormalizer::normalizeNoteItems($noteItems);
        if ($items === []) {
            return [];
        }

        $qty = max(1, $requestedQuantity);
        $meta = $this->metadata($qty, $items);
        $lines = [
            'SYSTEM AUTOMATION POLICY ('.$this->policyLabel().'):',
            '- requested_quantity='.$qty.' is the total desired generated articles.',
            '- Distinguish source_type=cluster (expand an existing Topic/MCP area) vs source_type=manual_seed (create a NEW semantic area from free-form seed_text).',
            '- For each item: missing_slots = max(0, target_dna_count - SUM(dna.slots)). AI MUST derive that many additional distinct DNA/content angles.',
            '- Specified DNA phrases are explicit user demand; preserve them with placement (before|after).',
            '- placement=before → DNA is guided BEFORE the base topic/keyword; placement=after → AFTER. Do not invert.',
            '- Repeated phrase slots (e.g. quà tặng ×3) = repeated semantic demand, NOT permission to duplicate search intent — diversify angles.',
            '- Avoid cannibalizing / duplicate search intent vs existing Articles, Draft items, and ideas already accepted in this run; preserve site primary language and Planning Intelligence.',
            '- For manual_seed: do NOT require SeoTopicClusterMeta, cluster_key, MCP, focus articles, or membership in the current keyword inventory. Generate genuinely NEW keyword opportunities from seed_text when useful.',
            '- For cluster: use cluster name, MCP, existing DNA, keyword inventory, and focus articles to expand an existing semantic area.',
        ];

        if ($meta['total_topic_target'] < $qty) {
            $lines[] = '- Topic targets total '.$meta['total_topic_target'].' < requested_quantity '.$qty
                .': allocate remaining article slots via Planning Intelligence (expand selected Topics/Seeds and/or broader site opportunities) while keeping diversity.';
        }

        foreach ($items as $item) {
            $missing = AuditNoteDnaNormalizer::missingSlotCount(
                (int) $item['target_dna_count'],
                $item['dna'],
            );
            if ($missing <= 0) {
                continue;
            }
            if (AuditNoteDnaNormalizer::isManualSeed($item)) {
                $seed = trim((string) ($item['seed_text'] ?? $item['cluster_name_snapshot'] ?? ''));
                $lines[] = '- Planning Seed "'.$seed.'": derive '.$missing
                    .' additional useful, non-duplicative search/content angles from scratch (missing_slots='.$missing.'). New keywords not already in inventory are allowed.';
            } else {
                $lines[] = '- Topic "'.$item['cluster_name_snapshot'].'": derive '.$missing
                    .' additional useful, non-duplicative search/content angles (missing_slots='.$missing.').';
            }
        }

        return $lines;
    }

    public function policyLabel(): string
    {
        return self::KEY.' v'.self::VERSION;
    }

    /**
     * @param  list<array<string, mixed>>  $noteItems
     */
    public function appliesTo(array $noteItems): bool
    {
        return AuditNoteDnaNormalizer::normalizeNoteItems($noteItems) !== [];
    }
}
