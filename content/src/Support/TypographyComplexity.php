<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Dữ liệu phức tạp typography — parser Phase 2 điền đầy đủ.
 */
final class TypographyComplexity
{
    /**
     * @param  list<array{id: string, text: string, required: bool, weight: float, type: string}>  $visibleTextBlocks
     */
    public function __construct(
        public readonly ?int $visibleTextChars = null,
        public readonly ?int $textBlockCount = null,
        public readonly ?int $maxTextBlockLength = null,
        public readonly ?string $layoutType = null,
        public readonly ?int $nodeCount = null,
        public readonly bool $exactTextRequired = false,
        public readonly ?string $language = null,
        public readonly array $visibleTextBlocks = [],
        public readonly int $titleCount = 0,
        public readonly int $labelCount = 0,
        public readonly int $paragraphCount = 0,
        public readonly int $panelCount = 0,
        public readonly int $relationCount = 0,
        public readonly string $visualDensity = 'normal',
        public readonly float $complexityScore = 0.0,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->visibleTextBlocks === []
            && $this->visibleTextChars === null
            && $this->textBlockCount === null
            && $this->maxTextBlockLength === null
            && ($this->layoutType === null || $this->layoutType === '')
            && $this->nodeCount === null
            && ! $this->exactTextRequired
            && ($this->language === null || $this->language === '')
            && $this->complexityScore <= 0.0;
    }

    public function isLight(): bool
    {
        return $this->complexityScore < 0.35;
    }

    public function isHeavy(): bool
    {
        return $this->complexityScore >= 0.65;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'visible_text_chars' => $this->visibleTextChars,
            'text_block_count' => $this->textBlockCount,
            'max_text_block_length' => $this->maxTextBlockLength,
            'layout_type' => $this->layoutType,
            'node_count' => $this->nodeCount,
            'exact_text_required' => $this->exactTextRequired,
            'language' => $this->language,
            'title_count' => $this->titleCount,
            'label_count' => $this->labelCount,
            'paragraph_count' => $this->paragraphCount,
            'panel_count' => $this->panelCount,
            'visual_density' => $this->visualDensity,
            'complexity_score' => $this->complexityScore,
            'visible_text_block_count' => count($this->visibleTextBlocks),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        $blocks = is_array($data['visible_text_blocks'] ?? null) ? $data['visible_text_blocks'] : [];

        return new self(
            visibleTextChars: isset($data['visible_text_chars']) ? (int) $data['visible_text_chars'] : null,
            textBlockCount: isset($data['text_block_count']) ? (int) $data['text_block_count'] : null,
            maxTextBlockLength: isset($data['max_text_block_length']) ? (int) $data['max_text_block_length'] : null,
            layoutType: isset($data['layout_type']) ? (string) $data['layout_type'] : null,
            nodeCount: isset($data['node_count']) ? (int) $data['node_count'] : null,
            exactTextRequired: (bool) ($data['exact_text_required'] ?? false),
            language: isset($data['language']) ? (string) $data['language'] : null,
            visibleTextBlocks: self::normalizeBlocks($blocks),
            titleCount: (int) ($data['title_count'] ?? 0),
            labelCount: (int) ($data['label_count'] ?? 0),
            paragraphCount: (int) ($data['paragraph_count'] ?? 0),
            panelCount: (int) ($data['panel_count'] ?? 0),
            relationCount: (int) ($data['relation_count'] ?? 0),
            visualDensity: (string) ($data['visual_density'] ?? 'normal'),
            complexityScore: (float) ($data['complexity_score'] ?? 0.0),
        );
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<array{id: string, text: string, required: bool, weight: float, type: string}>
     */
    private static function normalizeBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $text = trim((string) ($block['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $normalized[] = [
                'id' => trim((string) ($block['id'] ?? 'block_'.$index)) ?: 'block_'.$index,
                'text' => $text,
                'required' => (bool) ($block['required'] ?? true),
                'weight' => (float) ($block['weight'] ?? 1.0),
                'type' => trim((string) ($block['type'] ?? 'body')) ?: 'body',
            ];
        }

        return $normalized;
    }
}
