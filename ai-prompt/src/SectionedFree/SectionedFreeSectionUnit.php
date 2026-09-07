<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * One independent generation unit for sectioned_free.
 */
final class SectionedFreeSectionUnit
{
    public const ROLE_INTRO = 'introduction';

    public const ROLE_BODY = 'body';

    public const ROLE_CONCLUSION = 'conclusion';

    public const ROLE_FAQ = 'faq';

    /**
     * @param  list<array{kind: string, heading: string, level: int, body: string}>  $outlineNodes
     * @param  list<string>  $requiredPoints
     */
    public function __construct(
        public readonly string $sectionId,
        public readonly int $order,
        public readonly string $label,
        public readonly string $role,
        public readonly array $outlineNodes,
        public readonly array $requiredPoints,
        public readonly int $targetMinWords,
        public readonly int $targetMaxWords,
        public readonly int $preferredTargetWords,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'section_id' => $this->sectionId,
            'section_order' => $this->order,
            'label' => $this->label,
            'role' => $this->role,
            'outline_nodes' => $this->outlineNodes,
            'required_points' => $this->requiredPoints,
            'target_min_words' => $this->targetMinWords,
            'target_max_words' => $this->targetMaxWords,
            'preferred_target_words' => $this->preferredTargetWords,
        ];
    }

    public function scopeMarkdown(): string
    {
        $parts = [];
        foreach ($this->outlineNodes as $node) {
            $level = max(1, min(3, (int) ($node['level'] ?? 2)));
            $heading = trim((string) ($node['heading'] ?? ''));
            $body = trim((string) ($node['body'] ?? ''));
            $block = str_repeat('#', $level).' '.$heading;
            if ($body !== '') {
                $block .= "\n".$body;
            }
            $parts[] = $block;
        }

        return implode("\n\n", $parts);
    }

    public function inputHash(): string
    {
        return hash('sha256', $this->sectionId.'|'.$this->scopeMarkdown());
    }
}
