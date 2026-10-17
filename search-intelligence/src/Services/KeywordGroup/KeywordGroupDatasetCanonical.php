<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

/**
 * Canonical dataset hash shared with seo-ops-semantic dataset_hash.py.
 *
 * SHA-256 over JSON with sorted object keys, compact separators, and UTF-8
 * left unescaped. Empty metadata is an object. Empty evidence is an array.
 */
final class KeywordGroupDatasetCanonical
{
    /**
     * @param  list<array{ref: string, text: string, language: ?string, industry_evidence: list<array{key: string, group_type: string}>, metadata: array<string, mixed>}>  $items
     */
    public static function datasetHash(string $scopeRef, ?string $language, array $items): string
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = self::item($item);
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($left['ref'], $right['ref']));

        return hash('sha256', self::encode([
            'items' => $rows,
            'language' => $language,
            'scope_ref' => $scopeRef,
        ]));
    }

    public static function contentHash(string $text): string
    {
        return hash('sha256', self::normalizeText($text));
    }

    public static function normalizeText(string $text): string
    {
        $value = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if (! is_string($value)) {
            $value = $text;
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  array{ref: string, text: string, language: ?string, industry_evidence?: list<array{key: string, group_type: string}>, metadata?: array<string, mixed>}  $item
     * @return array{content_hash: string, industry_evidence: list<array{group_type: string, key: string}>, language: ?string, metadata: \stdClass, ref: string}
     */
    private static function item(array $item): array
    {
        $evidence = [];
        foreach ($item['industry_evidence'] ?? [] as $membership) {
            $evidence[] = [
                'group_type' => (string) $membership['group_type'],
                'key' => (string) $membership['key'],
            ];
        }
        usort($evidence, static function (array $left, array $right): int {
            $byKey = strcmp($left['key'], $right['key']);

            return $byKey !== 0 ? $byKey : strcmp($left['group_type'], $right['group_type']);
        });

        $metadata = new \stdClass();
        foreach ($item['metadata'] ?? [] as $key => $value) {
            $metadata->{(string) $key} = $value;
        }

        return [
            'content_hash' => self::contentHash((string) $item['text']),
            'industry_evidence' => $evidence,
            'language' => $item['language'] ?? null,
            'metadata' => $metadata,
            'ref' => (string) $item['ref'],
        ];
    }

    private static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($encoded)) {
                throw new \RuntimeException('dataset_json_encode_failed');
            }

            return $encoded;
        }
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            $parts = [];
            foreach ($fields as $key => $item) {
                $parts[] = self::encode((string) $key).':'.self::encode($item);
            }

            return '{'.implode(',', $parts).'}';
        }
        if (! is_array($value)) {
            throw new \RuntimeException('dataset_json_type');
        }
        if (array_is_list($value)) {
            return '['.implode(',', array_map(self::encode(...), $value)).']';
        }
        ksort($value, SORT_STRING);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = self::encode((string) $key).':'.self::encode($item);
        }

        return '{'.implode(',', $parts).'}';
    }
}
