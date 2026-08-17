<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Move block within section â€” pure reorder mirror + source contracts.
 * JS source of truth: resources/js/utils/articleEditorBlockReorder.js
 */
final class ArticleEditorMoveBlockWithinSectionTest extends TestCase
{
    public function test_move_up_swaps_with_previous_neighbor(): void
    {
        $blocks = $this->sampleBlocks();
        $sectionIds = ['a', 'b', 'c', 'd'];
        $result = $this->reorder($blocks, 'b', 'up', $sectionIds);

        self::assertTrue($result['ok']);
        self::assertSame('moved', $result['code']);
        self::assertSame(['b', 'a', 'c', 'd'], array_column($result['blocks'], 'id'));
        self::assertSame('paragraph-b', $result['blocks'][0]['content']);
        self::assertSame('b', $result['blocks'][0]['id']);
    }

    public function test_move_down_swaps_with_next_neighbor(): void
    {
        $blocks = $this->sampleBlocks();
        $result = $this->reorder($blocks, 'b', 'down', ['a', 'b', 'c', 'd']);

        self::assertTrue($result['ok']);
        self::assertSame(['a', 'c', 'b', 'd'], array_column($result['blocks'], 'id'));
    }

    public function test_preserves_block_identity_and_metadata(): void
    {
        $image = [
            'id' => 'img1',
            'type' => 'image',
            'content' => '<figure>x</figure>',
            'image' => ['src' => 'https://cdn.example/a.jpg', 'alt' => 'keep'],
            'meta' => ['seo_media_id' => 99],
        ];
        $blocks = [
            ['id' => 'a', 'type' => 'text', 'content' => 'A'],
            $image,
            ['id' => 'c', 'type' => 'text', 'content' => 'C'],
        ];
        $result = $this->reorder($blocks, 'img1', 'up', ['a', 'img1', 'c']);
        self::assertTrue($result['ok']);
        self::assertSame('img1', $result['blocks'][0]['id']);
        self::assertSame($image['image'], $result['blocks'][0]['image']);
        self::assertSame($image['meta'], $result['blocks'][0]['meta']);
        self::assertSame($image['content'], $result['blocks'][0]['content']);
    }

    public function test_first_block_up_is_noop(): void
    {
        $result = $this->reorder($this->sampleBlocks(), 'a', 'up', ['a', 'b', 'c', 'd']);
        self::assertFalse($result['ok']);
        self::assertSame('block_already_first', $result['code']);
        self::assertSame(['a', 'b', 'c', 'd'], array_column($result['blocks'], 'id'));
    }

    public function test_last_block_down_is_noop(): void
    {
        $result = $this->reorder($this->sampleBlocks(), 'd', 'down', ['a', 'b', 'c', 'd']);
        self::assertFalse($result['ok']);
        self::assertSame('block_already_last', $result['code']);
    }

    public function test_does_not_cross_into_other_section_ids(): void
    {
        $blocks = [
            ['id' => 's1a', 'type' => 'text', 'content' => '1'],
            ['id' => 's1b', 'type' => 'text', 'content' => '2'],
            ['id' => 's2a', 'type' => 'text', 'content' => '3'],
            ['id' => 's2b', 'type' => 'text', 'content' => '4'],
        ];
        $result = $this->reorder($blocks, 's1a', 'up', ['s1a', 's1b']);
        self::assertFalse($result['ok']);
        self::assertSame('block_already_first', $result['code']);
        self::assertSame(['s1a', 's1b', 's2a', 's2b'], array_column($result['blocks'], 'id'));

        $downEdge = $this->reorder($blocks, 's1b', 'down', ['s1a', 's1b']);
        self::assertFalse($downEdge['ok']);
        self::assertSame('block_already_last', $downEdge['code']);
        self::assertSame(['s1a', 's1b', 's2a', 's2b'], array_column($downEdge['blocks'], 'id'));
    }

    public function test_other_section_unaffected_when_reordering(): void
    {
        $blocks = [
            ['id' => 's1a', 'type' => 'text', 'content' => '1'],
            ['id' => 's1b', 'type' => 'text', 'content' => '2'],
            ['id' => 's2a', 'type' => 'text', 'content' => '3'],
            ['id' => 's2b', 'type' => 'text', 'content' => '4'],
        ];
        $result = $this->reorder($blocks, 's1b', 'up', ['s1a', 's1b']);
        self::assertTrue($result['ok']);
        self::assertSame(['s1b', 's1a', 's2a', 's2b'], array_column($result['blocks'], 'id'));
        self::assertSame('3', $result['blocks'][2]['content']);
        self::assertSame('4', $result['blocks'][3]['content']);
    }

    public function test_blockquote_and_table_move_as_whole_blocks(): void
    {
        $blocks = [
            ['id' => 'p1', 'type' => 'text', 'content' => '<p>A</p>'],
            ['id' => 'q1', 'type' => 'text', 'content' => '<blockquote><p>1</p><p>2</p><p>3</p></blockquote>'],
            ['id' => 't1', 'type' => 'text', 'content' => '<table><tr><td>x</td></tr></table>'],
        ];
        $up = $this->reorder($blocks, 'q1', 'up', ['p1', 'q1', 't1']);
        self::assertTrue($up['ok']);
        self::assertSame('q1', $up['blocks'][0]['id']);
        self::assertStringContainsString('<blockquote>', $up['blocks'][0]['content']);
        self::assertStringContainsString('<p>3</p>', $up['blocks'][0]['content']);

        $down = $this->reorder($blocks, 'q1', 'down', ['p1', 'q1', 't1']);
        self::assertTrue($down['ok']);
        self::assertSame('t1', $down['blocks'][1]['id']);
        self::assertSame('q1', $down['blocks'][2]['id']);
    }

    public function test_no_duplicate_ids_after_reorder(): void
    {
        $result = $this->reorder($this->sampleBlocks(), 'c', 'up', ['a', 'b', 'c', 'd']);
        $ids = array_column($result['blocks'], 'id');
        self::assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_js_util_exports_canonical_api(): void
    {
        $src = $this->js('utils/articleEditorBlockReorder.js');
        self::assertStringContainsString('export function reorderBlockWithinSection', $src);
        self::assertStringContainsString('export function withinSectionMoveAvailability', $src);
        self::assertStringContainsString('block_already_first', $src);
        self::assertStringContainsString('block_already_last', $src);
        self::assertStringNotContainsString('querySelector', $src);
        self::assertStringNotContainsString('JSON.parse(JSON.stringify', $src);
        self::assertStringNotContainsString('structuredClone', $src);
    }

    public function test_command_registered_separate_from_adjacent(): void
    {
        $registry = $this->js('utils/editorCommands/editorCommandRegistry.js');
        self::assertStringContainsString("mut('move_block_within_section'", $registry);
        self::assertStringContainsString("mut('move_block_to_adjacent_section'", $registry);

        $structure = $this->js('utils/editorCommands/structureCommands.js');
        self::assertStringContainsString('moveBlockWithinSectionCommand', $structure);
        self::assertStringContainsString('moveBlockToAdjacentSectionCommand', $structure);
        self::assertStringContainsString('move_block_within_section', $structure);
        $within = $this->extractFunction($structure, 'moveBlockWithinSectionCommand');
        self::assertStringContainsString("runHostStructure(context, 'move_block_within_section'", $within);
        self::assertStringNotContainsString('move_block_to_adjacent_section', $within);
        self::assertStringNotContainsString("direction: 'prev'", $within);
        self::assertStringNotContainsString("direction: 'next'", $within);
    }

    public function test_host_handles_within_section_via_structure_mutation(): void
    {
        $hook = $this->js('hooks/useArticleEditorInsertAndSections.js');
        self::assertStringContainsString("name === 'move_block_within_section'", $hook);
        self::assertStringContainsString('applyMoveBlockWithinSectionMutation', $hook);
        self::assertStringContainsString('reorderBlockWithinSection', $hook);
        $fn = $this->extractFunction($hook, 'applyMoveBlockWithinSectionMutation');
        self::assertStringNotContainsString('normalizeBlocks(', $fn);
        self::assertStringContainsString('setActiveBlockId(blockId)', $fn);
        self::assertStringContainsString('setBlocks(result.blocks)', $fn);
    }

    public function test_ui_has_single_and_double_arrows_with_distinct_tooltips(): void
    {
        $bar = $this->js('components/BlockInsertMenu.jsx');
        self::assertStringContainsString('ArrowUp', $bar);
        self::assertStringContainsString('ArrowDown', $bar);
        self::assertStringContainsString('ChevronsUp', $bar);
        self::assertStringContainsString('ChevronsDown', $bar);
        self::assertStringContainsString('editor_move_block_up_within_section', $bar);
        self::assertStringContainsString('editor_move_block_down_within_section', $bar);
        self::assertStringContainsString('editor_move_block_prev_section', $bar);
        self::assertStringContainsString('editor_move_block_next_section', $bar);
        self::assertStringContainsString('canMoveUpWithinSection', $bar);
        self::assertStringContainsString('canMoveDownWithinSection', $bar);
        self::assertStringContainsString('seo-block-move-btn--within', $bar);
        self::assertStringContainsString('seo-block-move-btn--section', $bar);

        $i18n = $this->js('utils/i18n.js');
        self::assertStringContainsString("editor_move_block_up_within_section: 'Move up within section'", $i18n);
        self::assertStringContainsString("editor_move_block_down_within_section: 'Move down within section'", $i18n);
        self::assertStringContainsString("editor_move_block_prev_section: 'Move to previous section'", $i18n);
        self::assertStringContainsString('editor_move_block_up_within_section:', $i18n);
        self::assertStringContainsString('editor_move_block_prev_section:', $i18n);
    }

    public function test_double_arrow_behavior_preserved(): void
    {
        $editor = $this->js('components/SeoArticleEditor.jsx');
        self::assertStringContainsString('moveBlockToSection', $editor);
        $hook = $this->js('hooks/useArticleEditorInsertAndSections.js');
        self::assertStringContainsString('moveBlockToSection', $hook);
        self::assertStringContainsString("name === 'move_block_to_adjacent_section'", $hook);
    }

    public function test_emit_document_changed_single_path_for_structure_ok_result(): void
    {
        $structure = $this->js('utils/editorCommands/structureCommands.js');
        $runHost = $this->extractFunction($structure, 'runHostStructure');
        self::assertStringContainsString('emitDocumentChanged', $runHost);
        self::assertStringContainsString("hasOwnProperty.call(result, 'ok')", $runHost);
    }

    public function test_command_codes_include_boundary_codes(): void
    {
        $codes = $this->js('utils/editorCommands/editorCommandResult.js');
        self::assertStringContainsString("BLOCK_ALREADY_FIRST: 'block_already_first'", $codes);
        self::assertStringContainsString("BLOCK_ALREADY_LAST: 'block_already_last'", $codes);
        self::assertStringContainsString("MOVED: 'moved'", $codes);
    }

    /**
     * Mirror of articleEditorBlockReorder.js â€” keep in sync.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $sectionBlockIds
     * @return array{ok: bool, code: string, blocks: list<array<string, mixed>>, fromIndex: int, toIndex: int}
     */
    private function reorder(array $blocks, string $blockId, string $direction, array $sectionBlockIds): array
    {
        $localIndex = array_search($blockId, $sectionBlockIds, true);
        if ($localIndex === false) {
            return ['ok' => false, 'code' => 'section_mismatch', 'blocks' => $blocks, 'fromIndex' => -1, 'toIndex' => -1];
        }
        $localIndex = (int) $localIndex;
        if ($direction === 'up' && $localIndex === 0) {
            return ['ok' => false, 'code' => 'block_already_first', 'blocks' => $blocks, 'fromIndex' => $localIndex, 'toIndex' => $localIndex];
        }
        if ($direction === 'down' && $localIndex === count($sectionBlockIds) - 1) {
            return ['ok' => false, 'code' => 'block_already_last', 'blocks' => $blocks, 'fromIndex' => $localIndex, 'toIndex' => $localIndex];
        }

        $swapIndex = $direction === 'up' ? $localIndex - 1 : $localIndex + 1;
        $nextSectionIds = $sectionBlockIds;
        $tmp = $nextSectionIds[$localIndex];
        $nextSectionIds[$localIndex] = $nextSectionIds[$swapIndex];
        $nextSectionIds[$swapIndex] = $tmp;

        $idToBlock = [];
        foreach ($blocks as $block) {
            $idToBlock[(string) $block['id']] = $block;
        }

        $firstId = $sectionBlockIds[0];
        $lastId = $sectionBlockIds[count($sectionBlockIds) - 1];
        $firstAbs = null;
        $lastAbs = null;
        foreach ($blocks as $i => $block) {
            if ((string) $block['id'] === $firstId) {
                $firstAbs = $i;
            }
            if ((string) $block['id'] === $lastId) {
                $lastAbs = $i;
            }
        }
        if ($firstAbs === null || $lastAbs === null || $lastAbs < $firstAbs) {
            return ['ok' => false, 'code' => 'section_missing', 'blocks' => $blocks, 'fromIndex' => -1, 'toIndex' => -1];
        }

        $reorderedSlice = [];
        foreach ($nextSectionIds as $id) {
            $reorderedSlice[] = $idToBlock[$id];
        }

        $next = array_merge(
            array_slice($blocks, 0, $firstAbs),
            $reorderedSlice,
            array_slice($blocks, $lastAbs + 1),
        );

        return [
            'ok' => true,
            'code' => 'moved',
            'blocks' => $next,
            'fromIndex' => $localIndex,
            'toIndex' => $swapIndex,
        ];
    }

    /** @return list<array{id: string, type: string, content: string}> */
    private function sampleBlocks(): array
    {
        return [
            ['id' => 'a', 'type' => 'text', 'content' => 'paragraph-a'],
            ['id' => 'b', 'type' => 'text', 'content' => 'paragraph-b'],
            ['id' => 'c', 'type' => 'text', 'content' => 'paragraph-c'],
            ['id' => 'd', 'type' => 'text', 'content' => 'paragraph-d'],
        ];
    }

    private function js(string $relative): string
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function extractFunction(string $source, string $name): string
    {
        $pattern = '/(?:export\s+)?function\s+'.preg_quote($name, '/').'\s*\(/';
        $isArrow = false;
        if (! preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
            $pattern = '/(?:const|let)\s+'.preg_quote($name, '/').'\s*=\s*(?:async\s*)?\(/';
            if (! preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                self::fail("Function {$name} not found");
            }
            $isArrow = true;
        }
        $start = (int) $m[0][1];
        $i = $start + strlen($m[0][0]);
        $paren = 1;
        $len = strlen($source);
        while ($i < $len && $paren > 0) {
            $ch = $source[$i];
            if ($ch === '(') {
                $paren++;
            } elseif ($ch === ')') {
                $paren--;
            }
            $i++;
        }
        if ($isArrow) {
            $arrow = strpos($source, '=>', $i);
            self::assertNotFalse($arrow);
            $i = $arrow + 2;
        }
        $brace = strpos($source, '{', $i);
        self::assertNotFalse($brace);
        $depth = 0;
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail("Unbalanced braces for {$name}");
    }
}
