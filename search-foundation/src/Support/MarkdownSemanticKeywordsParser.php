<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Support;

/**
 * Bóc tách Markdown (### Nhóm + danh sách -) thành map nhóm → mảng từ khóa.
 */
final class MarkdownSemanticKeywordsParser
{
    /**
     * @return array<string, list<string>>
     */
    public function parse(string $markdown): array
    {
        $groups = [];
        $currentKey = null;

        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^###\s+(.+)$/u', $trimmed, $matches) === 1) {
                $currentKey = trim($matches[1]);
                if ($currentKey !== '' && ! isset($groups[$currentKey])) {
                    $groups[$currentKey] = [];
                }

                continue;
            }

            if ($currentKey === null) {
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches) === 1) {
                $item = trim($matches[1]);
                if ($item !== '') {
                    $groups[$currentKey][] = $item;
                }
            }
        }

        return $groups;
    }
}
