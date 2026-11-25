<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support\Vocabulary;

/**
 * Parse/render Task 2 vocabulary markdown (### group headings + bullet lists).
 */
final class VocabularyMarkdownCodec
{
    public const ITEMS_PER_GROUP = 5;

    /** @var list<string> */
    public const CANONICAL_GROUP_HEADINGS = [
        'Holonymy',
        'Synonyms',
        'Antonyms',
        'Long-tail keywords',
        'Semantic keywords',
        'Salient keywords',
        'Salient LSI keywords',
        'Semantic LSI entities',
        'Relational entities',
        'Relevant entities',
        'Semantic entities',
        'Close entities',
        'Salient entities',
        'Related topics',
        'Unigrams',
        'Bigrams',
        'Trigrams',
        'Quadgrams',
        'Quinquigrams',
    ];

    /**
     * @return array<string, list<string>>
     */
    public function parse(string $markdown): array
    {
        $groups = [];
        $current = null;

        foreach (preg_split("/\r\n|\n|\r/", $markdown) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^#{3}\s+(.+?)\s*$/u', $trimmed, $matches) === 1) {
                $current = $this->normalizeHeading((string) $matches[1]);
                $groups[$current] ??= [];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches) !== 1) {
                continue;
            }

            $item = trim((string) $matches[1]);
            if ($item !== '') {
                $groups[$current][] = $item;
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, list<string>>  $groups
     */
    public function render(array $groups): string
    {
        $lines = [];
        $ordered = $this->orderGroups($groups);

        foreach ($ordered as $heading => $items) {
            $deduped = $this->dedupeItems($items);
            if ($deduped === []) {
                continue;
            }

            // Contiguous groups (no blank lines) — matches direct vocabulary contract.
            $lines[] = '### '.$heading;
            $count = 0;
            foreach ($deduped as $item) {
                $lines[] = '- '.$item;
                $count++;
                if ($count >= self::ITEMS_PER_GROUP) {
                    break;
                }
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @return array<string, list<string>>
     */
    public function orderGroups(array $groups): array
    {
        $ordered = [];

        foreach (self::CANONICAL_GROUP_HEADINGS as $heading) {
            if (! isset($groups[$heading]) || $groups[$heading] === []) {
                continue;
            }
            $ordered[$heading] = $groups[$heading];
        }

        foreach ($groups as $heading => $items) {
            if (isset($ordered[$heading]) || $items === []) {
                continue;
            }
            $ordered[$this->normalizeHeading((string) $heading)] = $items;
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    public function dedupeItems(array $items): array
    {
        $seen = [];
        $out = [];

        foreach ($items as $item) {
            $phrase = trim((string) $item);
            if ($phrase === '') {
                continue;
            }
            $key = mb_strtolower($phrase);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $phrase;
        }

        return $out;
    }

    private function normalizeHeading(string $heading): string
    {
        $heading = trim(preg_replace('/^#+\s*/u', '', $heading) ?? $heading);
        $heading = trim(str_replace(['**', '*'], '', $heading));

        foreach (self::CANONICAL_GROUP_HEADINGS as $canonical) {
            if (mb_strtolower($canonical) === mb_strtolower($heading)) {
                return $canonical;
            }
        }

        return $heading;
    }
}