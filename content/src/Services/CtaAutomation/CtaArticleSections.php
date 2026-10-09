<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

/**
 * Splits article HTML into stable section ids from one shared document walk.
 */
final class CtaArticleSections
{
    /**
     * @return list<array{section_id: string, heading: string, position_ratio: float, start_word: int, word_count: int, content: string}>
     */
    public function extract(string $html): array
    {
        $root = CtaSectionMap::root($html);
        if ($root === null) {
            return [];
        }

        $rows = [];
        foreach (CtaSectionMap::bind($root) as $section) {
            unset($section['nodes']);
            $rows[] = $section;
        }

        return $rows;
    }
}
