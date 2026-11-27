<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Outline H2 Move Up/Down — pure section-swap mirror + source contracts.
 * JS source of truth: resources/js/utils/articleEditorBlockReorder.js
 */
final class ArticleEditorOutlineH2SectionMoveTest extends TestCase
{
    public function test_move_up_swaps_whole_section_including_h3_and_table(): void
    {
        $result = $this->swap($this->sampleBlocks(), $this->sampleSections(), 'section-h2-b', 'prev');

        self::assertTrue($result['ok']);
        self::assertSame('moved', $result['code']);
        self::assertSame(
            ['intro', 'h2-b', 'p-b', 'h4-b', 'h2-a', 'p-a', 'h3-a', 'table-a', 'h2-c', 'p-c'],
            array_column($result['blocks'], 'id'),
        );
        self::assertSame('<h4>Beta nested</h4>', $result['blocks'][3]['content'] ?? null);
        self::assertSame('<table><tr><td>A</td></tr></table>', $result['blocks'][7]['content'] ?? null);
        self::assertSame('intro', $result['blocks'][0]['id']);
    }

    public function test_move_down_swaps_with_next_section(): void
    {
        $result = $this->swap($this->sampleBlocks(), $this->sampleSections(), 'section-h2-b', 'next');

        self::assertTrue($result['ok']);
        self::assertSame(
            ['intro', 'h2-a', 'p-a', 'h3-a', 'table-a', 'h2-c', 'p-c', 'h2-b', 'p-b', 'h4-b'],
            array_column($result['blocks'], 'id'),
        );
    }

    public function test_first_h2_cannot_move_above_intro(): void
    {
        $blocks = $this->sampleBlocks();
        $result = $this->swap($blocks, $this->sampleSections(), 'section-h2-a', 'prev');

        self::assertFalse($result['ok']);
        self::assertSame('intro_protected', $result['code']);
        self::assertSame(array_column($blocks, 'id'), array_column($result['blocks'], 'id'));
    }

    public function test_last_h2_cannot_move_down(): void
    {
        $result = $this->swap($this->sampleBlocks(), $this->sampleSections(), 'section-h2-c', 'next');

        self::assertFalse($result['ok']);
        self::assertSame('target_section_missing', $result['code']);
    }

    public function test_duplicate_h2_titles_keep_block_ids_and_content(): void
    {
        $blocks = $this->sampleBlocks();
        $result = $this->swap($blocks, $this->sampleSections(), 'section-h2-c', 'prev');

        self::assertTrue($result['ok']);
        self::assertSame(
            ['intro', 'h2-a', 'p-a', 'h3-a', 'table-a', 'h2-c', 'p-c', 'h2-b', 'p-b', 'h4-b'],
            array_column($result['blocks'], 'id'),
        );
        self::assertSame('<h2>Alpha</h2>', $result['blocks'][1]['content']);
        self::assertSame('<h2>Alpha</h2>', $result['blocks'][5]['content']);
        self::assertSame(count($blocks), count(array_unique(array_column($result['blocks'], 'id'))));
    }

    public function test_js_exports_section_swap_api(): void
    {
        $src = $this->js('utils/articleEditorBlockReorder.js');
        self::assertStringContainsString('export function swapAdjacentEditorSections', $src);
        self::assertStringContainsString('export function outlineH2MoveAvailability', $src);
        self::assertStringContainsString('intro_protected', $src);
        self::assertStringContainsString('target_section_missing', $src);
        self::assertStringNotContainsString('structuredClone', $src);
    }

    public function test_outline_ui_exposes_h2_only_move_controls(): void
    {
        $tab = $this->js('components/ArticleOutlineTab.jsx');
        self::assertStringContainsString('outline_move_up', $tab);
        self::assertStringContainsString('outline_move_down', $tab);
        self::assertStringContainsString('outlineH2MoveAvailability', $tab);
        self::assertStringContainsString('swapH2SiblingInTree', $tab);
        self::assertStringContainsString("Number(node.level) === 2 && canMoveUp", $tab);
        self::assertStringContainsString("Number(node.level) === 2 && canMoveDown", $tab);
        self::assertStringContainsString("Number(node?.level ?? 0) !== 2", $tab);

        $hook = $this->js('hooks/useArticleEditorInsertAndSections.js');
        self::assertStringContainsString('swapAdjacentEditorSections', $hook);
        self::assertStringContainsString('handleOutlineMoveHeading', $hook);
        self::assertStringContainsString("Number(node.level ?? 0) !== 2", $hook);
        self::assertStringContainsString('node.block_id', $hook);

        $editor = $this->js('components/SeoArticleEditor.jsx');
        self::assertStringContainsString('onOutlineMoveHeading={handleOutlineMoveHeading}', $editor);

        $i18n = $this->js('utils/i18n.js');
        self::assertStringContainsString('outline_move_up:', $i18n);
        self::assertStringContainsString('outline_move_down:', $i18n);
    }

    public function test_planning_outline_meta_not_rewritten_by_move_path(): void
    {
        $hook = $this->js('hooks/useArticleEditorInsertAndSections.js');
        $move = $this->extractFunction($hook, 'moveSection');
        $outlineMove = $this->extractFunction($hook, 'handleOutlineMoveHeading');

        self::assertStringNotContainsString('seo_article_outline', $move);
        self::assertStringNotContainsString('seo_article_outline', $outlineMove);
        self::assertStringNotContainsString('PromptResult', $move);
        self::assertStringNotContainsString('PromptResult', $outlineMove);
        self::assertStringContainsString('swapAdjacentEditorSections', $move);
    }

    /**
     * Mirror of swapAdjacentEditorSections — keep in sync with JS.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{id: string, isIntro?: bool, blockIds: list<string>}>  $sections
     * @return array{ok: bool, code: string, blocks: list<array<string, mixed>>}
     */
    private function swap(array $blocks, array $sections, string $sectionId, string $direction): array
    {
        $currentIndex = null;
        foreach ($sections as $i => $section) {
            if ((string) ($section['id'] ?? '') === $sectionId) {
                $currentIndex = $i;
                break;
            }
        }
        if ($currentIndex === null) {
            return ['ok' => false, 'code' => 'section_missing', 'blocks' => $blocks];
        }

        $section = $sections[$currentIndex];
        if (! empty($section['isIntro'])) {
            return ['ok' => false, 'code' => 'intro_protected', 'blocks' => $blocks];
        }

        $targetIndex = $direction === 'prev' ? $currentIndex - 1 : $currentIndex + 1;
        if ($targetIndex < 0 || $targetIndex >= count($sections)) {
            return ['ok' => false, 'code' => 'target_section_missing', 'blocks' => $blocks];
        }

        $targetSection = $sections[$targetIndex];
        if (! empty($targetSection['isIntro'])) {
            return ['ok' => false, 'code' => 'intro_protected', 'blocks' => $blocks];
        }

        $movedIds = array_values(array_filter(array_map('strval', $section['blockIds'] ?? [])));
        $targetIds = array_values(array_filter(array_map('strval', $targetSection['blockIds'] ?? [])));
        if ($movedIds === [] || $targetIds === []) {
            return ['ok' => false, 'code' => 'blocks_mismatch', 'blocks' => $blocks];
        }

        $byId = [];
        foreach ($blocks as $block) {
            $byId[(string) $block['id']] = $block;
        }

        $fromBlocks = [];
        foreach ($movedIds as $id) {
            if (! isset($byId[$id])) {
                return ['ok' => false, 'code' => 'blocks_mismatch', 'blocks' => $blocks];
            }
            $fromBlocks[] = $byId[$id];
        }

        $movedSet = array_fill_keys($movedIds, true);
        $withoutMoved = [];
        foreach ($blocks as $block) {
            if (! isset($movedSet[(string) $block['id']])) {
                $withoutMoved[] = $block;
            }
        }

        $targetStart = null;
        foreach ($withoutMoved as $i => $block) {
            if ((string) $block['id'] === $targetIds[0]) {
                $targetStart = $i;
                break;
            }
        }
        if ($targetStart === null) {
            return ['ok' => false, 'code' => 'target_section_missing', 'blocks' => $blocks];
        }

        $insertAt = $direction === 'prev'
            ? $targetStart
            : $targetStart + count($targetIds);

        $next = array_merge(
            array_slice($withoutMoved, 0, $insertAt),
            $fromBlocks,
            array_slice($withoutMoved, $insertAt),
        );

        return ['ok' => true, 'code' => 'moved', 'blocks' => $next];
    }

    /** @return list<array{id: string, type: string, content: string}> */
    private function sampleBlocks(): array
    {
        return [
            ['id' => 'intro', 'type' => 'text', 'content' => '<p>Intro stays first</p>'],
            ['id' => 'h2-a', 'type' => 'text', 'content' => '<h2>Alpha</h2>'],
            ['id' => 'p-a', 'type' => 'text', 'content' => '<p>Alpha body</p>'],
            ['id' => 'h3-a', 'type' => 'text', 'content' => '<h3>Alpha child</h3>'],
            ['id' => 'table-a', 'type' => 'text', 'content' => '<table><tr><td>A</td></tr></table>'],
            ['id' => 'h2-b', 'type' => 'text', 'content' => '<h2>Beta</h2>'],
            ['id' => 'p-b', 'type' => 'text', 'content' => '<p>Beta body</p>'],
            ['id' => 'h4-b', 'type' => 'text', 'content' => '<h4>Beta nested</h4>'],
            ['id' => 'h2-c', 'type' => 'text', 'content' => '<h2>Alpha</h2>'],
            ['id' => 'p-c', 'type' => 'text', 'content' => '<p>Gamma body with duplicate H2 title</p>'],
        ];
    }

    /** @return list<array{id: string, isIntro: bool, blockIds: list<string>}> */
    private function sampleSections(): array
    {
        return [
            ['id' => 'section-intro', 'isIntro' => true, 'blockIds' => ['intro']],
            ['id' => 'section-h2-a', 'isIntro' => false, 'blockIds' => ['h2-a', 'p-a', 'h3-a', 'table-a']],
            ['id' => 'section-h2-b', 'isIntro' => false, 'blockIds' => ['h2-b', 'p-b', 'h4-b']],
            ['id' => 'section-h2-c', 'isIntro' => false, 'blockIds' => ['h2-c', 'p-c']],
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
                $pattern = '/(?:const|let)\s+'.preg_quote($name, '/').'\s*=\s*useCallback\s*\(\s*(?:async\s*)?\(/';
                if (! preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                    self::fail("Function {$name} not found");
                }
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
