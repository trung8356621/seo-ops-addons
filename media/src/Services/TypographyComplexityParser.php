<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Content\Support\TypographyComplexity;

/**
 * Parse visible text blocks và complexity từ compiled prompt / blueprint.
 */
final class TypographyComplexityParser
{
    /**
     * @param  array<string, string>  $variables
     */
    public function parse(string $compiledPrompt, array $variables = []): TypographyComplexity
    {
        $compiledPrompt = trim($compiledPrompt);
        $userBrief = trim((string) ($variables['input'] ?? $variables['user_brief'] ?? ''));

        $blocks = $this->extractVisibleTextBlocks($compiledPrompt);
        if ($blocks === [] && $userBrief !== '') {
            $blocks = $this->extractVisibleTextBlocks($userBrief);
        }

        $layoutType = $this->detectLayoutType($compiledPrompt);
        $nodeCount = $this->countLayoutNodes($compiledPrompt, $layoutType);
        $panelCount = max(1, (int) preg_match_all('/\bpanel[_\s-]?\d+/iu', $compiledPrompt));
        $relationCount = max(0, (int) preg_match_all('/\b(edge|arrow|connector|liên kết|mũi tên)\b/iu', $compiledPrompt));

        $titleCount = count(array_filter($blocks, static fn (array $b): bool => ($b['type'] ?? '') === 'title'));
        $labelCount = count(array_filter($blocks, static fn (array $b): bool => ($b['type'] ?? '') === 'label'));
        $paragraphCount = count(array_filter($blocks, static fn (array $b): bool => ($b['type'] ?? '') === 'body'));

        $visibleChars = array_sum(array_map(
            static fn (array $block): int => mb_strlen((string) ($block['text'] ?? '')),
            $blocks,
        ));
        $maxBlockLength = $blocks === []
            ? 0
            : max(array_map(static fn (array $block): int => mb_strlen((string) ($block['text'] ?? '')), $blocks));

        $exactTextRequired = $this->detectExactTextRequired($compiledPrompt, $blocks);
        $language = $this->detectLanguage($blocks, $compiledPrompt);
        $visualDensity = $this->visualDensity($visibleChars, count($blocks), $nodeCount);
        $complexityScore = $this->computeComplexityScore(
            visibleChars: $visibleChars,
            blockCount: count($blocks),
            maxBlockLength: $maxBlockLength,
            paragraphCount: $paragraphCount,
            nodeCount: $nodeCount,
            panelCount: $panelCount,
            layoutType: $layoutType,
            exactTextRequired: $exactTextRequired,
            language: $language,
        );

        return new TypographyComplexity(
            visibleTextChars: $visibleChars > 0 ? $visibleChars : null,
            textBlockCount: $blocks !== [] ? count($blocks) : null,
            maxTextBlockLength: $maxBlockLength > 0 ? $maxBlockLength : null,
            layoutType: $layoutType,
            nodeCount: $nodeCount > 0 ? $nodeCount : null,
            exactTextRequired: $exactTextRequired,
            language: $language,
            visibleTextBlocks: $blocks,
            titleCount: $titleCount,
            labelCount: $labelCount,
            paragraphCount: $paragraphCount,
            panelCount: $panelCount,
            relationCount: $relationCount,
            visualDensity: $visualDensity,
            complexityScore: $complexityScore,
        );
    }

    /**
     * @return list<array{id: string, text: string, required: bool, weight: float, type: string}>
     */
    private function extractVisibleTextBlocks(string $text): array
    {
        $blocks = [];

        if (preg_match('/```json\s*(\{[\s\S]*?\})\s*```/iu', $text, $jsonFence)) {
            $blocks = array_merge($blocks, $this->blocksFromJson($jsonFence[1]));
        }

        if (preg_match('/\{[\s\S]*"visible_text(?:_blocks)?"[\s\S]*\}/iu', $text, $inlineJson)) {
            $blocks = array_merge($blocks, $this->blocksFromJson($inlineJson[0]));
        }

        if (preg_match_all('/<visible[_-]?text[^>]*>([\s\S]*?)<\/visible[_-]?text>/iu', $text, $xmlMatches)) {
            foreach ($xmlMatches[1] as $index => $segment) {
                $segmentText = trim(strip_tags((string) $segment));
                if ($segmentText !== '') {
                    $blocks[] = $this->makeBlock('visible_'.$index, $segmentText, 'body', true);
                }
            }
        }

        if (preg_match_all('/(?:^|\n)\s*(?:#{1,3}\s*)?(?:visible\s*text|chữ\s*hiển\s*thị|text\s*on\s*image)\s*:?\s*\n([\s\S]*?)(?=\n\s*(?:#{1,3}\s|\[|\{|---\s*$|$))/iu', $text, $sectionMatches)) {
            foreach ($sectionMatches[1] as $section) {
                $blocks = array_merge($blocks, $this->blocksFromSection((string) $section));
            }
        }

        if (preg_match_all('/\b(title|heading|label|cta|số liệu|number)\s*:\s*["“](.+?)["”]/iu', $text, $labeledQuotes, PREG_SET_ORDER)) {
            foreach ($labeledQuotes as $index => $match) {
                $type = $this->normalizeBlockType((string) ($match[1] ?? 'body'));
                $blocks[] = $this->makeBlock($type.'_'.$index, trim((string) ($match[2] ?? '')), $type, true);
            }
        }

        if (preg_match_all('/\[\s*EXACT[_\s-]?TEXT\s*:\s*([^\]]+)\]/iu', $text, $exactMarkers)) {
            foreach ($exactMarkers[1] as $index => $markerText) {
                $blocks[] = $this->makeBlock('exact_'.$index, trim((string) $markerText), 'label', true);
            }
        }

        if (preg_match_all('/"text"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/u', $text, $jsonTextFields)) {
            foreach ($jsonTextFields[1] as $index => $raw) {
                $decoded = stripcslashes((string) $raw);
                if (trim($decoded) !== '' && mb_strlen($decoded) <= 500) {
                    $blocks[] = $this->makeBlock('json_text_'.$index, trim($decoded), 'body', true);
                }
            }
        }

        return $this->dedupeBlocks($blocks);
    }

    /**
     * @return list<array{id: string, text: string, required: bool, weight: float, type: string}>
     */
    private function blocksFromJson(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $rawBlocks = $decoded['visible_text_blocks']
            ?? $decoded['visible_text']
            ?? $decoded['text_blocks']
            ?? null;

        if (! is_array($rawBlocks)) {
            return [];
        }

        $blocks = [];
        foreach ($rawBlocks as $index => $item) {
            if (is_string($item)) {
                $blocks[] = $this->makeBlock('block_'.$index, trim($item), 'body', true);

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? $item['content'] ?? ''));
            if ($text === '') {
                continue;
            }

            $blocks[] = $this->makeBlock(
                trim((string) ($item['id'] ?? 'block_'.$index)) ?: 'block_'.$index,
                $text,
                $this->normalizeBlockType((string) ($item['type'] ?? 'body')),
                (bool) ($item['required'] ?? true),
                (float) ($item['weight'] ?? 1.0),
            );
        }

        return $blocks;
    }

    /**
     * @return list<array{id: string, text: string, required: bool, weight: float, type: string}>
     */
    private function blocksFromSection(string $section): array
    {
        $blocks = [];
        $lines = preg_split('/\R/u', $section) ?: [];

        foreach ($lines as $index => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^[-*•]\s*(.+)$/u', $line, $bullet)) {
                $blocks[] = $this->makeBlock('section_'.$index, trim((string) $bullet[1]), 'body', true);

                continue;
            }

            if (preg_match('/^(.+?)\s*:\s*(.+)$/u', $line, $kv)) {
                $type = $this->normalizeBlockType((string) $kv[1]);
                $blocks[] = $this->makeBlock($type.'_'.$index, trim((string) $kv[2]), $type, true);

                continue;
            }

            $blocks[] = $this->makeBlock('section_'.$index, $line, 'body', true);
        }

        return $blocks;
    }

    /**
     * @param  list<array{id: string, text: string, required: bool, weight: float, type: string}>  $blocks
     * @return list<array{id: string, text: string, required: bool, weight: float, type: string}>
     */
    private function dedupeBlocks(array $blocks): array
    {
        $seen = [];
        $result = [];

        foreach ($blocks as $block) {
            $key = mb_strtolower(trim((string) ($block['text'] ?? '')));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $block;
        }

        return $result;
    }

    /**
     * @return array{id: string, text: string, required: bool, weight: float, type: string}
     */
    private function makeBlock(string $id, string $text, string $type, bool $required, float $weight = 1.0): array
    {
        return [
            'id' => $id,
            'text' => $text,
            'required' => $required,
            'weight' => $weight,
            'type' => $type,
        ];
    }

    private function normalizeBlockType(string $raw): string
    {
        $lower = mb_strtolower(trim($raw));

        return match (true) {
            str_contains($lower, 'title') || str_contains($lower, 'heading') => 'title',
            str_contains($lower, 'label') => 'label',
            str_contains($lower, 'cta') || str_contains($lower, 'button') => 'cta',
            str_contains($lower, 'number') || str_contains($lower, 'số') => 'number',
            default => 'body',
        };
    }

    private function detectLayoutType(string $text): ?string
    {
        $lower = mb_strtolower($text);

        return match (true) {
            str_contains($lower, 'mindmap') || str_contains($lower, 'mind map') || str_contains($lower, 'sơ đồ tư duy') => 'mindmap',
            str_contains($lower, 'flowchart') || str_contains($lower, 'flow chart') || str_contains($lower, 'lưu đồ') => 'flowchart',
            str_contains($lower, 'infographic') || str_contains($lower, 'infographic') => 'infographic',
            str_contains($lower, 'table') || str_contains($lower, 'bảng') => 'table',
            str_contains($lower, 'process') || str_contains($lower, 'quy trình') => 'process',
            default => null,
        };
    }

    private function countLayoutNodes(string $text, ?string $layoutType): int
    {
        $nodeMatches = preg_match_all('/\bnode[_\s-]?\d+/iu', $text);
        $panelMatches = preg_match_all('/\bpanel[_\s-]?\d+/iu', $text);

        $count = max((int) $nodeMatches, (int) $panelMatches);

        if ($count > 0) {
            return $count;
        }

        return match ($layoutType) {
            'mindmap', 'flowchart', 'process' => max(2, (int) preg_match_all('/\b(step|bước|nhánh)\s*\d+/iu', $text)),
            'table' => max(1, (int) preg_match_all('/\|/u', $text) / 3),
            default => 0,
        };
    }

    /**
     * @param  list<array{id: string, text: string, required: bool, weight: float, type: string}>  $blocks
     */
    private function detectExactTextRequired(string $text, array $blocks): bool
    {
        if (preg_match('/\b(exact\s*text|chính\s*xác|không\s*được\s*sai\s*chữ)\b/iu', $text)) {
            return true;
        }

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'body' || ($block['required'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: string, text: string, required: bool, weight: float, type: string}>  $blocks
     */
    private function detectLanguage(array $blocks, string $text): ?string
    {
        $sample = $blocks !== []
            ? implode(' ', array_map(static fn (array $b): string => (string) ($b['text'] ?? ''), $blocks))
            : $text;

        if (preg_match('/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu', $sample)) {
            return 'vi';
        }

        if (preg_match('/\p{L}/u', $sample)) {
            return 'latin';
        }

        return null;
    }

    private function visualDensity(int $visibleChars, int $blockCount, int $nodeCount): string
    {
        $signal = $visibleChars + ($blockCount * 40) + ($nodeCount * 25);

        return match (true) {
            $signal >= 900 => 'dense',
            $signal >= 450 => 'normal',
            default => 'light',
        };
    }

    private function computeComplexityScore(
        int $visibleChars,
        int $blockCount,
        int $maxBlockLength,
        int $paragraphCount,
        int $nodeCount,
        int $panelCount,
        ?string $layoutType,
        bool $exactTextRequired,
        ?string $language,
    ): float {
        $score = 0.0;
        $score += min(0.30, $visibleChars / 500 * 0.30);
        $score += min(0.20, $blockCount / 10 * 0.20);
        $score += min(0.15, $maxBlockLength / 200 * 0.15);
        $score += min(0.10, $paragraphCount / 8 * 0.10);
        $score += min(0.10, max($nodeCount, $panelCount) / 12 * 0.10);

        $score += match ($layoutType) {
            'mindmap' => 0.15,
            'flowchart', 'process' => 0.10,
            'table' => 0.08,
            'infographic' => 0.12,
            default => 0.0,
        };

        if ($exactTextRequired) {
            $score += 0.15;
        }

        if ($language === 'vi') {
            $score += 0.05;
        }

        return max(0.0, min(1.0, round($score, 4)));
    }
}
