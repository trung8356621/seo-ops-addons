<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 â€” pure Document selectors (no React).
 */
final class DocumentSelectorsTest extends TestCase
{
    public function test_selectors_are_pure_and_cover_core_nodes(): void
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/utils/documentSelectors.js';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        foreach ([
            'selectHeadings',
            'selectH2',
            'selectLinks',
            'selectImages',
            'selectParagraphs',
            'selectFaqPlaceholders',
            'selectCtaParagraphs',
            'selectTables',
            'selectWordCount',
        ] as $symbol) {
            self::assertStringContainsString($symbol, $source);
        }
        self::assertStringNotContainsString('from \'react\'', $source);
        self::assertStringNotContainsString('from "react"', $source);
    }
}
