<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

/**
 * Note-item DNA snapshot helpers. Planning override only — never mutates Cluster DNA SSOT.
 *
 * @phpstan-type DnaRow array{phrase: string, weight: int, source: string}
 * @phpstan-type NoteItem array{
 *   cluster_ref: string,
 *   cluster_name_snapshot: string,
 *   mcp_share_snapshot: float,
 *   dna: list<DnaRow>
 * }
 */
final class AuditNoteDnaNormalizer
{
    public const SOURCE_CLUSTER = 'cluster';

    public const SOURCE_MANUAL = 'manual';

    public const MANUAL_REF_PREFIX = 'manual:';

    public const DEFAULT_WEIGHT = 1;

    public const MAX_DNA_PER_NOTE = 50;

    public const MAX_PROMPT_DNA_LINES = 40;

    /**
     * @param  list<array{value?: string, phrase?: string, count?: int, weight?: int}>  $clusterDna
     * @return list<DnaRow>
     */
    public static function snapshotFromClusterDna(array $clusterDna): array
    {
        $rows = [];
        foreach ($clusterDna as $branch) {
            if (! is_array($branch)) {
                continue;
            }
            $phrase = self::displayPhrase((string) ($branch['phrase'] ?? $branch['value'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $weight = (int) ($branch['weight'] ?? $branch['count'] ?? self::DEFAULT_WEIGHT);
            if ($weight < 1) {
                $weight = self::DEFAULT_WEIGHT;
            }
            $rows[] = [
                'phrase' => $phrase,
                'weight' => $weight,
                'source' => self::SOURCE_CLUSTER,
            ];
        }

        return self::sortDna(self::mergeByPhrase($rows));
    }

    /**
     * @param  list<DnaRow>|list<array<string, mixed>>  $dna
     * @return list<DnaRow>
     */
    public static function normalizeDnaList(array $dna): array
    {
        $rows = [];
        foreach ($dna as $row) {
            if (! is_array($row)) {
                continue;
            }
            $phrase = self::displayPhrase((string) ($row['phrase'] ?? $row['value'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $weight = (int) ($row['weight'] ?? $row['count'] ?? self::DEFAULT_WEIGHT);
            if ($weight < 1) {
                $weight = self::DEFAULT_WEIGHT;
            }
            $source = trim((string) ($row['source'] ?? self::SOURCE_MANUAL));
            if ($source !== self::SOURCE_CLUSTER && $source !== self::SOURCE_MANUAL) {
                $source = self::SOURCE_MANUAL;
            }
            $rows[] = [
                'phrase' => $phrase,
                'weight' => $weight,
                'source' => $source,
            ];
        }

        return self::sortDna(self::mergeByPhrase($rows));
    }

    /**
     * @param  list<DnaRow>  $dna
     * @return list<DnaRow>
     */
    public static function addDna(array $dna, string $phrase, ?int $weight = null, string $source = self::SOURCE_MANUAL): array
    {
        $phrase = self::displayPhrase($phrase);
        if ($phrase === '') {
            return self::normalizeDnaList($dna);
        }
        $weight ??= self::DEFAULT_WEIGHT;
        if ($weight < 1) {
            $weight = self::DEFAULT_WEIGHT;
        }
        $dna[] = [
            'phrase' => $phrase,
            'weight' => $weight,
            'source' => $source === self::SOURCE_CLUSTER ? self::SOURCE_CLUSTER : self::SOURCE_MANUAL,
        ];

        return array_slice(self::normalizeDnaList($dna), 0, self::MAX_DNA_PER_NOTE);
    }

    /**
     * @param  list<DnaRow>  $dna
     * @return list<DnaRow>
     */
    public static function removeDna(array $dna, string $phrase): array
    {
        $needle = self::normalizeKey($phrase);
        if ($needle === '') {
            return self::normalizeDnaList($dna);
        }

        $kept = [];
        foreach ($dna as $row) {
            if (! is_array($row)) {
                continue;
            }
            $current = self::normalizeKey((string) ($row['phrase'] ?? ''));
            if ($current === '' || $current === $needle) {
                continue;
            }
            $kept[] = $row;
        }

        return self::normalizeDnaList($kept);
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
        $name = trim((string) ($item['cluster_name_snapshot'] ?? $item['cluster_name'] ?? $item['label'] ?? ''));
        if ($name === '') {
            $name = $ref;
        }
        $share = (float) ($item['mcp_share_snapshot'] ?? $item['mcp_share'] ?? $item['topical_share'] ?? 0);
        if ($share < 0) {
            $share = 0.0;
        }

        return [
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => $name,
            'mcp_share_snapshot' => round($share, 1),
            'dna' => self::normalizeDnaList(is_array($item['dna'] ?? null) ? $item['dna'] : []),
        ];
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
     * @param  list<DnaRow>  $dna
     * @return list<string>
     */
    public static function promptLines(array $dna, int $maxLines = self::MAX_PROMPT_DNA_LINES): array
    {
        $lines = [];
        foreach (array_slice(self::normalizeDnaList($dna), 0, max(1, $maxLines)) as $row) {
            $lines[] = $row['weight'].' '.$row['phrase'];
        }

        return $lines;
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
            if (! isset($byNorm[$key])) {
                $byNorm[$key] = $row;

                continue;
            }
            $existing = $byNorm[$key];
            if ($row['weight'] > $existing['weight']) {
                $existing['weight'] = $row['weight'];
                $existing['phrase'] = $row['phrase'];
            }
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
                $byWeight = $b['weight'] <=> $a['weight'];
                if ($byWeight !== 0) {
                    return $byWeight;
                }

                return strcmp($a['phrase'], $b['phrase']);
            },
        );

        return array_values($rows);
    }
}
