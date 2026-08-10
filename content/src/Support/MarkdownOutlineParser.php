<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Bóc tách Markdown (H2/H3) thành cấu trúc outline JSON.
 */
final class MarkdownOutlineParser
{
    /**
     * @return array{sections: list<array{title: string, children: list<array{title: string}>}>}
     */
    public function parse(string $markdown): array
    {
        $sections = [];

        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^##\s+(.+)$/u', $trimmed, $matches) === 1) {
                $sections[] = [
                    'title' => trim($matches[1]),
                    'children' => [],
                ];

                continue;
            }

            if ($sections !== [] && preg_match('/^###\s+(.+)$/u', $trimmed, $matches) === 1) {
                $last = array_key_last($sections);
                $sections[$last]['children'][] = [
                    'title' => trim($matches[1]),
                ];
            }
        }

        return ['sections' => $sections];
    }
}
