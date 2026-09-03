<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

/**
 * Split article outline / markdown into H2 (and H3) semantic units.
 */
final class LongFormArticleSplitter
{
    /**
     * @param  array{
     *   title?: string,
     *   primary_keyword?: string,
     *   language?: string,
     *   outline?: string,
     *   source?: string,
     *   instructions?: string
     * }  $structured
     * @return list<SemanticContentChunk>
     */
    public function split(array $structured): array
    {
        $outline = trim((string) ($structured['outline'] ?? $structured['source'] ?? ''));
        if ($outline === '') {
            return [];
        }

        $immutable = $this->immutablePreamble($structured);
        $sections = $this->parseHeadingSections($outline);
        if ($sections === []) {
            return [(new SemanticContentChunk(
                logicalId: 'body-0',
                kind: 'body',
                body: $immutable."\n\n".$outline,
                order: 0,
                meta: ['role' => 'full'],
            ))->withHash()];
        }

        $chunks = [];
        $order = 0;
        foreach ($sections as $section) {
            $chunks[] = (new SemanticContentChunk(
                logicalId: 'section-'.$order,
                kind: (string) $section['kind'],
                body: $immutable."\n\n".$section['content'],
                order: $order,
                meta: [
                    'heading' => $section['heading'],
                    'level' => $section['level'],
                ],
            ))->withHash();
            $order++;
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function immutablePreamble(array $structured): string
    {
        $lines = ['ARTICLE CONTEXT (immutable):'];
        foreach (['title', 'primary_keyword', 'language', 'instructions'] as $key) {
            $value = trim((string) ($structured[$key] ?? ''));
            if ($value !== '') {
                $lines[] = strtoupper($key).': '.$value;
            }
        }
        $lines[] = 'Write ONLY the section provided. Do not repeat intro/conclusion unless this section is that role.';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{kind: string, heading: string, level: int, content: string}>
     */
    private function parseHeadingSections(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $sections = [];
        $current = null;

        $flush = static function () use (&$sections, &$current): void {
            if ($current === null) {
                return;
            }
            $body = trim((string) $current['content']);
            if ($body === '') {
                $current = null;

                return;
            }
            $sections[] = $current;
            $current = null;
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,3})\s+(.+)$/u', $line, $m) === 1) {
                $flush();
                $level = strlen($m[1]);
                $heading = trim($m[2]);
                $kind = match ($level) {
                    1 => str_contains(mb_strtolower($heading), 'intro') ? 'introduction' : 'h1',
                    2 => str_contains(mb_strtolower($heading), 'kết luận')
                        || str_contains(mb_strtolower($heading), 'conclusion')
                        ? 'conclusion'
                        : 'h2',
                    default => 'h3',
                };
                $current = [
                    'kind' => $kind,
                    'heading' => $heading,
                    'level' => $level,
                    'content' => $line."\n",
                ];
                continue;
            }
            if ($current === null) {
                $current = [
                    'kind' => 'introduction',
                    'heading' => 'Introduction',
                    'level' => 2,
                    'content' => '',
                ];
            }
            $current['content'] .= $line."\n";
        }
        $flush();

        return $sections;
    }
}
