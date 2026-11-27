<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteTargetAllocator;

/**
 * Pure helpers for Idea Manager add / bulk / import / dedupe (no I/O).
 */
final class IdeaWorkspaceItemOps
{
    public const MAX_BULK_LINES = 200;

    public const AUDIT_STORAGE_PREFIX = 'seoOps:content-planner:audit-notes:v2:site:';

    public const IM_DRAFT_PREFIX = 'seoOps:idea-manager:draft:v1:site:';

    /**
     * @param  list<array<string, mixed>>  $existing
     * @return array{item: array<string, mixed>|null, duplicate: bool}
     */
    public static function makeManualSeed(string $raw, array $existing): array
    {
        $seed = AuditNoteDnaNormalizer::normalizeSeedText($raw);
        if ($seed === '') {
            return ['item' => null, 'duplicate' => false];
        }
        if (self::findDuplicate($existing, null, $seed) !== null) {
            return ['item' => null, 'duplicate' => true];
        }

        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => AuditNoteDnaNormalizer::SOURCE_TYPE_MANUAL_SEED,
            'cluster_ref' => AuditNoteDnaNormalizer::manualSeedRef(),
            'cluster_name_snapshot' => $seed,
            'seed_text' => $seed,
            'mcp_share_snapshot' => null,
            'target_dna_count' => AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT,
            'target_mode' => AuditNoteTargetAllocator::TARGET_MODE_MANUAL,
            'dna' => [],
        ]);

        return ['item' => $item, 'duplicate' => false];
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @return array{
     *   valid: list<array<string, mixed>>,
     *   duplicates: list<string>,
     *   invalid: list<string>,
     *   truncated: bool
     * }
     */
    public static function previewBulkLines(string $textarea, array $existing): array
    {
        $lines = preg_split('/\R/u', $textarea) ?: [];
        $truncated = count($lines) > self::MAX_BULK_LINES;
        $lines = array_slice($lines, 0, self::MAX_BULK_LINES);

        $valid = [];
        $duplicates = [];
        $invalid = [];
        $seenKeys = [];

        foreach ($lines as $line) {
            $seed = AuditNoteDnaNormalizer::normalizeSeedText($line);
            if ($seed === '') {
                if (trim((string) $line) !== '') {
                    $invalid[] = trim((string) $line);
                }
                continue;
            }
            $key = AuditNoteDnaNormalizer::normalizeKey($seed);
            if (isset($seenKeys[$key]) || self::findDuplicate($existing, null, $seed) !== null) {
                $duplicates[] = $seed;
                continue;
            }
            $seenKeys[$key] = true;
            $made = self::makeManualSeed($seed, array_merge($existing, $valid));
            if ($made['item'] === null) {
                $duplicates[] = $seed;
                continue;
            }
            $valid[] = $made['item'];
        }

        return [
            'valid' => $valid,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  array<string, mixed>  $suggestion
     * @return array{item: array<string, mixed>|null, duplicate: bool}
     */
    public static function makeFromClusterSuggestion(array $suggestion, array $existing): array
    {
        $ref = trim((string) ($suggestion['cluster_ref'] ?? ''));
        $name = trim((string) ($suggestion['cluster_name'] ?? $suggestion['cluster_name_snapshot'] ?? ''));
        if ($ref === '' || $name === '') {
            return ['item' => null, 'duplicate' => false];
        }
        if (self::findDuplicate($existing, $ref, $name) !== null) {
            return ['item' => null, 'duplicate' => true];
        }

        $dna = [];
        if (is_array($suggestion['cluster_dna'] ?? null)) {
            $dna = AuditNoteDnaNormalizer::snapshotFromClusterDna($suggestion['cluster_dna']);
        }

        $item = AuditNoteDnaNormalizer::normalizeNoteItem([
            'source_type' => AuditNoteDnaNormalizer::SOURCE_TYPE_CLUSTER,
            'cluster_ref' => $ref,
            'cluster_name_snapshot' => $name,
            'mcp_share_snapshot' => $suggestion['mcp_share'] ?? $suggestion['mcp_share_snapshot'] ?? 0,
            'target_dna_count' => AuditNoteDnaNormalizer::DEFAULT_TARGET_DNA_COUNT,
            'target_mode' => AuditNoteTargetAllocator::TARGET_MODE_MANUAL,
            'dna' => $dna,
        ]);

        return ['item' => $item, 'duplicate' => false];
    }

    /**
     * Parse SEO Audit localStorage payload (read-only import).
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   importable: list<array<string, mixed>>,
     *   duplicates: list<array<string, mixed>>,
     *   invalid: int
     * }
     */
    public static function previewAuditImport(mixed $parsed, int $siteId, array $existing): array
    {
        if (! is_array($parsed)) {
            return ['items' => [], 'importable' => [], 'duplicates' => [], 'invalid' => 0];
        }
        $storedSite = (int) ($parsed['site_id'] ?? 0);
        if ($storedSite !== $siteId) {
            return ['items' => [], 'importable' => [], 'duplicates' => [], 'invalid' => 0];
        }
        $rawItems = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];
        $normalized = AuditNoteDnaNormalizer::normalizeNoteItems($rawItems);
        $invalid = max(0, count($rawItems) - count($normalized));

        $importable = [];
        $duplicates = [];
        foreach ($normalized as $item) {
            $ref = (string) ($item['cluster_ref'] ?? '');
            $name = (string) ($item['cluster_name_snapshot'] ?? $item['seed_text'] ?? '');
            if (self::findDuplicate(array_merge($existing, $importable), $ref, $name) !== null) {
                $duplicates[] = $item;
                continue;
            }
            $importable[] = $item;
        }

        return [
            'items' => $normalized,
            'importable' => $importable,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    public static function findDuplicate(array $items, ?string $clusterRef, ?string $name): ?array
    {
        $ref = trim((string) $clusterRef);
        $nameKey = $name !== null && $name !== ''
            ? AuditNoteDnaNormalizer::normalizeKey(AuditNoteDnaNormalizer::normalizeSeedText($name) ?: $name)
            : '';

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemRef = trim((string) ($item['cluster_ref'] ?? ''));
            if ($ref !== '' && $itemRef !== '' && $itemRef === $ref) {
                return $item;
            }
            $itemName = (string) ($item['cluster_name_snapshot'] ?? $item['seed_text'] ?? '');
            if ($nameKey !== '' && $itemName !== '') {
                $itemKey = AuditNoteDnaNormalizer::normalizeKey(
                    AuditNoteDnaNormalizer::normalizeSeedText($itemName) ?: $itemName
                );
                if ($itemKey === $nameKey) {
                    return $item;
                }
            }
        }

        return null;
    }
}
