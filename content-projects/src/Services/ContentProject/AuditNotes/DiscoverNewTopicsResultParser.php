<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes;

/**
 * Parse + validate seo_audit.discover_new_topics structured output.
 */
final class DiscoverNewTopicsResultParser
{
    /**
     * @return array{
     *   accepted: list<array{candidate_key: string, name: string, target_dna_count: int, dna: list<string>}>,
     *   rejected: list<array{reason: string, raw: mixed}>
     * }
     */
    public function parse(mixed $value): array
    {
        $payload = $this->decodePayload($value);
        if ($payload === null) {
            return [
                'accepted' => [],
                'rejected' => [['reason' => 'invalid_json', 'raw' => $value]],
            ];
        }

        $topics = $payload['topics'] ?? null;
        if (! is_array($topics)) {
            return [
                'accepted' => [],
                'rejected' => [['reason' => 'missing_topics', 'raw' => $payload]],
            ];
        }

        $accepted = [];
        $rejected = [];
        $seenKeys = [];

        foreach ($topics as $index => $row) {
            if (! is_array($row)) {
                $rejected[] = ['reason' => 'row_not_object', 'raw' => $row];

                continue;
            }

            $key = trim((string) ($row['candidate_key'] ?? ''));
            if ($key === '') {
                $key = 'generated-'.($index + 1);
            }
            if (isset($seenKeys[$key])) {
                $rejected[] = ['reason' => 'duplicate_candidate_key', 'raw' => $row];

                continue;
            }

            $name = AuditNoteDnaNormalizer::displayPhrase((string) ($row['name'] ?? ''));
            if ($name === '') {
                $rejected[] = ['reason' => 'empty_name', 'raw' => $row];

                continue;
            }

            $target = (int) ($row['target_dna_count'] ?? 0);
            if ($target < AuditNoteDnaNormalizer::MIN_TARGET_DNA_COUNT) {
                $rejected[] = ['reason' => 'invalid_target_dna_count', 'raw' => $row];

                continue;
            }
            $target = min(AuditNoteDnaNormalizer::MAX_TARGET_DNA_COUNT, $target);

            $dnaRaw = $row['dna'] ?? [];
            if (! is_array($dnaRaw)) {
                $rejected[] = ['reason' => 'dna_not_array', 'raw' => $row];

                continue;
            }

            $dna = $this->normalizeDnaPhrases($dnaRaw);
            $seenKeys[$key] = true;
            $accepted[] = [
                'candidate_key' => $key,
                'name' => $name,
                'target_dna_count' => $target,
                'dna' => $dna,
            ];
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * @param  list<mixed>  $dnaRaw
     * @return list<string>
     */
    private function normalizeDnaPhrases(array $dnaRaw): array
    {
        $out = [];
        $seen = [];
        foreach ($dnaRaw as $item) {
            $phrase = '';
            if (is_string($item)) {
                $phrase = AuditNoteDnaNormalizer::displayPhrase($item);
            } elseif (is_array($item)) {
                $phrase = AuditNoteDnaNormalizer::displayPhrase((string) ($item['phrase'] ?? $item['value'] ?? ''));
            }
            if ($phrase === '') {
                continue;
            }
            $key = AuditNoteDnaNormalizer::normalizeKey($phrase);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $phrase;
            if (count($out) >= AuditNoteDnaNormalizer::MAX_DNA_PER_NOTE) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
