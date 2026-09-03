<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\DnaPlacement;

/**
 * Note-item DNA snapshot helpers. Planning override only — never mutates Cluster DNA SSOT.
 *
 * Canonical DNA row uses phrase + slots + placement (planning demand). Legacy weight is NOT slot count.
 *
 * @phpstan-type DnaRow array{phrase: string, slots: int, source: string, placement: string}
 * @phpstan-type NoteItem array{
 *   source_type: string,
 *   cluster_ref: string,
 *   cluster_name_snapshot: string,
 *   seed_text: string|null,
 *   mcp_share_snapshot: float|null,
 *   target_dna_count: int,
 *   target_mode: string,
 *   dna: list<DnaRow>
 * }
 */
final class AuditNoteDnaNormalizer
{
    public const SOURCE_CLUSTER = 'cluster';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_TYPE_CLUSTER = 'cluster';

    public const SOURCE_TYPE_MANUAL_SEED = 'manual_seed';

    public const MANUAL_REF_PREFIX = 'manual:';

    /** @deprecated Legacy Cluster fallback only — never treat as planner slot multiplicity. */
    public const DEFAULT_WEIGHT = 1;

    /** @deprecated Retained for older UI contract tests; planner uses DEFAULT_TARGET_DNA_COUNT. */
    public const DEFAULT_MANUAL_WEIGHT = 5;

    public const DEFAULT_TARGET_DNA_COUNT = 5;

    public const MIN_TARGET_DNA_COUNT = 1;

    public const MAX_TARGET_DNA_COUNT = 100;

    public const MAX_SEED_TEXT_LENGTH = 300;

    public const DEFAULT_SLOTS = 1;

    public const MAX_DNA_PER_NOTE = 50;

    public const MAX_PROMPT_DNA_LINES = 40;

    public const PLACEMENT_BEFORE = DnaPlacement::BEFORE;

    public const PLACEMENT_AFTER = DnaPlacement::AFTER;

    public const DEFAULT_PLACEMENT = DnaPlacement::DEFAULT;

    /**
     * Cluster DNA → unique phrases as ONE specified slot each (ignore historical weight/count).
     * Preserves source placement when valid; otherwise defaults to after.
     *
     * @param  list<array{value?: string, phrase?: string, count?: int, weight?: int, slots?: int, placement?: string}>  $clusterDna
     * @return list<DnaRow>
     */
    public static function snapshotFromClusterDna(array $clusterDna): array
    {
        $rows = [];
        foreach ($clusterDna as $branch) {
            if (is_string($branch)) {
                $phrase = self::displayPhrase($branch);
                if ($phrase === '') {
                    continue;
                }
                $rows[] = [
                    'phrase' => $phrase,
                    'slots' => self::DEFAULT_SLOTS,
                    'source' => self::SOURCE_CLUSTER,
                    'placement' => self::DEFAULT_PLACEMENT,
                ];

                continue;
            }
            if (! is_array($branch)) {
                continue;
            }
            $phrase = self::displayPhrase((string) ($branch['phrase'] ?? $branch['value'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $rows[] = [
                'phrase' => $phrase,
                'slots' => self::DEFAULT_SLOTS,
                'source' => self::SOURCE_CLUSTER,
                'placement' => self::normalizePlacement($branch['placement'] ?? null),
            ];
        }

        $unique = [];
        foreach (self::mergeByPhrase($rows) as $row) {
            $unique[] = [
                'phrase' => $row['phrase'],
                'slots' => self::DEFAULT_SLOTS,
                'source' => self::SOURCE_CLUSTER,
                'placement' => $row['placement'],
            ];
        }

        return self::sortDna($unique);
    }

    /**
     * @param  list<DnaRow>|list<array<string, mixed>>|list<string>  $dna
     * @return list<DnaRow>
     */
    public static function normalizeDnaList(array $dna): array
    {
        $rows = [];
        foreach ($dna as $row) {
            if (is_string($row)) {
                $phrase = self::displayPhrase($row);
                if ($phrase === '') {
                    continue;
                }
                $rows[] = [
                    'phrase' => $phrase,
                    'slots' => self::DEFAULT_SLOTS,
                    'source' => self::SOURCE_MANUAL,
                    'placement' => self::DEFAULT_PLACEMENT,
                ];

                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $phrase = self::displayPhrase((string) ($row['phrase'] ?? $row['value'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $source = trim((string) ($row['source'] ?? self::SOURCE_MANUAL));
            if ($source !== self::SOURCE_CLUSTER && $source !== self::SOURCE_MANUAL) {
                $source = self::SOURCE_MANUAL;
            }
            $rows[] = [
                'phrase' => $phrase,
                'slots' => self::normalizeSlotsFromLegacyRow($row),
                'source' => $source,
                'placement' => self::normalizePlacement($row['placement'] ?? null),
            ];
        }

        return array_slice(self::sortDna(self::mergeByPhrase($rows)), 0, self::MAX_DNA_PER_NOTE);
    }

    public static function normalizePlacement(mixed $raw): string
    {
        return DnaPlacement::normalize($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function normalizeSlotsFromLegacyRow(array $row): int
    {
        if (array_key_exists('slots', $row)) {
            $slots = (int) $row['slots'];

            return $slots < 1 ? self::DEFAULT_SLOTS : min(self::MAX_TARGET_DNA_COUNT, $slots);
        }

        return self::DEFAULT_SLOTS;
    }

    /**
     * @param  list<DnaRow>|list<array<string, mixed>>|list<string>  $dna
     * @return list<DnaRow>
     */
    public static function addDna(
        array $dna,
        string $phrase,
        ?int $weight = null,
        string $source = self::SOURCE_MANUAL,
        ?string $placement = null,
    ): array {
        unset($weight);
        $phrase = self::displayPhrase($phrase);
        if ($phrase === '') {
            return self::normalizeDnaList($dna);
        }
        $dna[] = [
            'phrase' => $phrase,
            'slots' => self::DEFAULT_SLOTS,
            'source' => $source === self::SOURCE_CLUSTER ? self::SOURCE_CLUSTER : self::SOURCE_MANUAL,
            'placement' => self::normalizePlacement($placement ?? self::DEFAULT_PLACEMENT),
        ];

        return self::normalizeDnaList($dna);
    }

    /**
     * @param  list<DnaRow>|list<array<string, mixed>>|list<string>  $dna
     * @return list<DnaRow>
     */
    public static function setDnaPlacement(array $dna, string $phrase, string $placement): array
    {
        $needle = self::normalizeKey($phrase);
        if ($needle === '') {
            return self::normalizeDnaList($dna);
        }
        $normalizedPlacement = self::normalizePlacement($placement);
        $out = [];
        foreach (self::normalizeDnaList($dna) as $row) {
            if (self::normalizeKey($row['phrase']) === $needle) {
                $row['placement'] = $normalizedPlacement;
            }
            $out[] = $row;
        }

        return self::normalizeDnaList($out);
    }

    /**
     * @param  list<DnaRow>|list<array<string, mixed>>|list<string>  $dna
     * @return list<DnaRow>
     */
    public static function duplicateDna(array $dna, string $phrase): array
    {
        $existing = null;
        foreach (self::normalizeDnaList($dna) as $row) {
            if (self::normalizeKey($row['phrase']) === self::normalizeKey($phrase)) {
                $existing = $row;
                break;
            }
        }
        $placement = is_array($existing) ? (string) ($existing['placement'] ?? self::DEFAULT_PLACEMENT) : self::DEFAULT_PLACEMENT;

        return self::addDna($dna, $phrase, null, self::SOURCE_MANUAL, $placement);
    }

    /**
     * @param  list<DnaRow>|list<array<string, mixed>>|list<string>  $dna
     * @return list<DnaRow>
     */
    public static function removeDnaSlot(array $dna, string $phrase): array
    {
        $needle = self::normalizeKey($phrase);
        if ($needle === '') {
            return self::normalizeDnaList($dna);
        }

        $normalized = self::normalizeDnaList($dna);
        $out = [];
        foreach ($normalized as $row) {
            if (self::normalizeKey($row['phrase']) !== $needle) {
                $out[] = $row;

                continue;
            }
            $slots = (int) $row['slots'] - 1;
            if ($slots >= 1) {
                $out[] = [
                    'phrase' => $row['phrase'],
                    'slots' => $slots,
                    'source' => $row['source'],
                    'placement' => $row['placement'],
                ];
            }
        }

        return self::normalizeDnaList($out);
    }

    /**
     * @param  list<DnaRow>|list<array<string, mixed>>|list<string>  $dna
     * @return list<DnaRow>
     */
    public static function removeDna(array $dna, string $phrase): array
    {
        $needle = self::normalizeKey($phrase);
        if ($needle === '') {
            return self::normalizeDnaList($dna);
        }

        $kept = [];
        foreach (self::normalizeDnaList($dna) as $row) {
            if (self::normalizeKey($row['phrase']) === $needle) {
                continue;
            }
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * @param  list<DnaRow>|list<array<string, mixed>>|list<string>  $dna
     */
    public static function specifiedSlotCount(array $dna): int
    {
        $total = 0;
        foreach (self::normalizeDnaList($dna) as $row) {
            $total += (int) $row['slots'];
        }

        return $total;
    }

    public static function missingSlotCount(int $targetDnaCount, array $dna): int
    {
        $target = self::normalizeTargetDnaCount($targetDnaCount);
        $specified = self::specifiedSlotCount($dna);

        return max(0, $target - $specified);
    }

    public static function normalizeTargetDnaCount(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_TARGET_DNA_COUNT;
        }
        $value = (int) $raw;
        if ($value < self::MIN_TARGET_DNA_COUNT || $value > self::MAX_TARGET_DNA_COUNT) {
            return self::DEFAULT_TARGET_DNA_COUNT;
        }

        return $value;
    }

    public static function ensureTargetCoversSpecified(int $targetDnaCount, array $dna): int
    {
        $target = self::normalizeTargetDnaCount($targetDnaCount);
        $specified = self::specifiedSlotCount($dna);
        if ($specified > $target) {
            return min(self::MAX_TARGET_DNA_COUNT, $specified);
        }

        return $target;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function totalTargetDnaCount(array $items): int
    {
        $total = 0;
        foreach (self::normalizeNoteItems($items) as $item) {
            $total += (int) $item['target_dna_count'];
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return NoteItem|null
     */
    public static function normalizeNoteItem(array $item): ?array
    {
        $ref = trim((string) ($item['cluster_ref'] ?? $item['cluster_key'] ?? ''));
        if ($ref === '') {
            return null;
        }

        $sourceType = self::normalizeSourceType($item['source_type'] ?? null, $ref);
        $name = trim((string) ($item['cluster_name_snapshot'] ?? $item['cluster_name'] ?? $item['label'] ?? ''));
        $seedText = null;
        $share = null;
        $targetModeRaw = $item['target_mode'] ?? null;

        if ($sourceType === self::SOURCE_TYPE_MANUAL_SEED) {
            $seedText = self::normalizeSeedText($item['seed_text'] ?? $name);
            if ($seedText === '') {
                return null;
            }
            $name = $seedText;
            $share = null;
            $targetModeRaw = AuditNoteTargetAllocator::TARGET_MODE_MANUAL;
        } else {
            if ($name === '') {
                $name = $ref;
            }
            $share = (float) ($item['mcp_share_snapshot'] ?? $item['mcp_share'] ?? $item['topical_share'] ?? 0);
            if ($share < 0) {
                $share = 0.0;
            }
            $share = round($share, 1);
        }

        $dna = self::normalizeDnaList(is_array($item['dna'] ?? null) ? $item['dna'] : []);
        $rawTarget = $item['target_dna_count'] ?? $item['topic_weight'] ?? null;
        $target = self::ensureTargetCoversSpecified(
            self::normalizeTargetDnaCount($rawTarget),
            $dna,
        );

        return [
            'source_type' => $sourceType,
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => $name,
            'seed_text' => $seedText,
            'mcp_share_snapshot' => $share,
            'target_dna_count' => $target,
            'target_mode' => AuditNoteTargetAllocator::normalizeTargetMode($targetModeRaw),
            'dna' => $dna,
        ];
    }

    public static function normalizeSourceType(mixed $raw, string $clusterRef = ''): string
    {
        $type = strtolower(trim((string) ($raw ?? '')));
        if ($type === self::SOURCE_TYPE_MANUAL_SEED || self::isManualRef($clusterRef)) {
            return self::SOURCE_TYPE_MANUAL_SEED;
        }

        return self::SOURCE_TYPE_CLUSTER;
    }

    public static function normalizeSeedText(mixed $raw): string
    {
        $text = self::displayPhrase((string) ($raw ?? ''));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_SEED_TEXT_LENGTH) {
            return mb_substr($text, 0, self::MAX_SEED_TEXT_LENGTH, 'UTF-8');
        }

        return $text;
    }

    public static function isManualSeed(array $item): bool
    {
        return self::normalizeSourceType($item['source_type'] ?? null, (string) ($item['cluster_ref'] ?? ''))
            === self::SOURCE_TYPE_MANUAL_SEED;
    }

    public static function manualSeedRef(): string
    {
        return self::MANUAL_REF_PREFIX.str_replace('-', '', (string) \Illuminate\Support\Str::uuid());
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<NoteItem>
     */
    public static function normalizeNoteItems(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalized = self::normalizeNoteItem($item);
            if ($normalized === null) {
                continue;
            }
            $ref = $normalized['cluster_ref'];
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * Human prompt lines keep placement markers for AI.
     *
     * @param  list<DnaRow>|list<array<string, mixed>>|list<string>  $dna
     * @return list<string>
     */
    public static function promptLines(array $dna, int $maxLines = self::MAX_PROMPT_DNA_LINES): array
    {
        $lines = [];
        foreach (array_slice(self::normalizeDnaList($dna), 0, max(1, $maxLines)) as $row) {
            $slots = (int) $row['slots'];
            $base = $row['phrase'].' · placement='.$row['placement'];
            $lines[] = $slots > 1 ? $base.' ×'.$slots : $base;
        }

        return $lines;
    }

    /**
     * Structured DNA payload for AI (never flatten to bare strings).
     *
     * @param  list<DnaRow>|list<array<string, mixed>>|list<string>  $dna
     * @return list<array{phrase: string, placement: string, slots: int}>
     */
    public static function structuredPromptDna(array $dna, int $maxRows = self::MAX_PROMPT_DNA_LINES): array
    {
        $out = [];
        foreach (array_slice(self::normalizeDnaList($dna), 0, max(1, $maxRows)) as $row) {
            $out[] = [
                'phrase' => $row['phrase'],
                'placement' => $row['placement'],
                'slots' => (int) $row['slots'],
            ];
        }

        return $out;
    }

    public static function displayPhrase(string $phrase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $phrase) ?? '');
    }

    public static function manualRef(string $name): string
    {
        $key = self::normalizeKey($name);
        if ($key === '') {
            return self::MANUAL_REF_PREFIX.'untitled';
        }

        return self::MANUAL_REF_PREFIX.$key;
    }

    public static function isManualRef(string $clusterRef): bool
    {
        return str_starts_with(trim($clusterRef), self::MANUAL_REF_PREFIX);
    }

    public static function normalizeKey(string $phrase): string
    {
        $phrase = self::displayPhrase($phrase);
        if ($phrase === '') {
            return '';
        }

        return mb_strtolower($phrase, 'UTF-8');
    }

    /**
     * Merge identical phrases by summing slots. Incoming placement wins (dedupe identity = phrase only).
     *
     * @param  list<DnaRow>  $rows
     * @return list<DnaRow>
     */
    private static function mergeByPhrase(array $rows): array
    {
        /** @var array<string, DnaRow> $byNorm */
        $byNorm = [];
        foreach ($rows as $row) {
            $key = self::normalizeKey($row['phrase']);
            if ($key === '') {
                continue;
            }
            $placement = self::normalizePlacement($row['placement'] ?? null);
            if (! isset($byNorm[$key])) {
                $byNorm[$key] = [
                    'phrase' => $row['phrase'],
                    'slots' => max(1, (int) $row['slots']),
                    'source' => $row['source'],
                    'placement' => $placement,
                ];

                continue;
            }
            $existing = $byNorm[$key];
            $existing['slots'] = min(
                self::MAX_TARGET_DNA_COUNT,
                (int) $existing['slots'] + max(1, (int) $row['slots']),
            );
            $existing['phrase'] = $row['phrase'];
            $existing['placement'] = $placement;
            if ($existing['source'] !== self::SOURCE_CLUSTER && $row['source'] === self::SOURCE_CLUSTER) {
                $existing['source'] = self::SOURCE_CLUSTER;
            }
            $byNorm[$key] = $existing;
        }

        return array_values($byNorm);
    }

    /**
     * @param  list<DnaRow>  $rows
     * @return list<DnaRow>
     */
    private static function sortDna(array $rows): array
    {
        usort(
            $rows,
            static function (array $a, array $b): int {
                $bySlots = $b['slots'] <=> $a['slots'];
                if ($bySlots !== 0) {
                    return $bySlots;
                }

                return strcmp($a['phrase'], $b['phrase']);
            },
        );

        return array_values($rows);
    }
}
