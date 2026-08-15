<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordAnchorSegmenter
{
    /**
     * Split long/descriptive anchors into classifier candidates only.
     * Does not insert canonical keywords.
     *
     * @return list<array{text: string, hint: string}>
     */
    public function segment(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*[–—|•·]\s+|\s+-\s+/u', $raw) ?: [$raw];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
        if (count($parts) < 2) {
            return [['text' => $raw, 'hint' => 'raw']];
        }

        $out = [];
        foreach ($parts as $i => $part) {
            $out[] = [
                'text' => $part,
                'hint' => $i === 0 ? 'head' : 'tail',
            ];
        }

        return $out;
    }
}
