<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

/**
 * HTML/Markdown-safe semantic blocks for rewrite / improve / translate.
 */
final class HtmlSafeContentSplitter
{
    /**
     * @param  array{source: string, instructions?: string, glossary?: string, language?: string}  $structured
     * @return list<SemanticContentChunk>
     */
    public function split(array $structured): array
    {
        $source = (string) ($structured['source'] ?? '');
        if (trim($source) === '') {
            return [];
        }

        $preamble = $this->preamble($structured);
        $blocks = $this->extractBlocks($source);
        $chunks = [];
        foreach ($blocks as $i => $block) {
            $chunks[] = (new SemanticContentChunk(
                logicalId: 'block-'.$i,
                kind: $block['kind'],
                body: $preamble."\n\nSOURCE BLOCK:\n".$block['html'],
                order: $i,
                meta: ['tag' => $block['kind']],
            ))->withHash();
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function preamble(array $structured): string
    {
        $lines = ['REWRITE/TRANSLATE CONTRACT (immutable):'];
        foreach (['instructions', 'glossary', 'language'] as $key) {
            $value = trim((string) ($structured[$key] ?? ''));
            if ($value !== '') {
                $lines[] = strtoupper($key).': '.$value;
            }
        }
        $lines[] = 'Return ONLY the transformed block. Preserve HTML tags, shortcodes, links, images, tables, and placeholders exactly.';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{kind: string, html: string}>
     */
    private function extractBlocks(string $source): array
    {
        $trimmed = trim($source);
        // Prefer top-level block elements; fall back to markdown H2 sections; else whole doc.
        if (preg_match_all(
            '/<(?P<tag>h[1-6]|p|ul|ol|table|blockquote|pre|figure|section|div)(\s[^>]*)?>.*?<\/(?P=tag)>/is',
            $trimmed,
            $matches,
            PREG_SET_ORDER,
        ) && $matches !== []) {
            $out = [];
            foreach ($matches as $match) {
                $html = trim((string) ($match[0] ?? ''));
                if ($html === '') {
                    continue;
                }
                $out[] = [
                    'kind' => strtolower((string) ($match['tag'] ?? 'block')),
                    'html' => $html,
                ];
            }
            if ($out !== []) {
                return $out;
            }
        }

        if (preg_match('/^#{1,3}\s+/m', $trimmed) === 1) {
            $sections = (new LongFormArticleSplitter())->split(['source' => $trimmed]);
            $out = [];
            foreach ($sections as $section) {
                $out[] = [
                    'kind' => $section->kind,
                    'html' => $section->body,
                ];
            }

            return $out;
        }

        // Paragraph groups separated by blank lines — never mid-tag.
        $parts = preg_split('/\n{2,}/u', $trimmed) ?: [$trimmed];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($this->looksBrokenTag($part)) {
                // Keep attaching to previous when possible.
                if ($out !== []) {
                    $out[array_key_last($out)]['html'] .= "\n\n".$part;
                    continue;
                }
            }
            $out[] = ['kind' => 'paragraph_group', 'html' => $part];
        }

        return $out !== [] ? $out : [['kind' => 'document', 'html' => $trimmed]];
    }

    private function looksBrokenTag(string $part): bool
    {
        return preg_match('/^[^<]*>/', $part) === 1
            || preg_match('/<[a-z]+[^>]*$/i', $part) === 1;
    }
}
