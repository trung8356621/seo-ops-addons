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
     * @param  list<array{
     *   kind: string,
     *   heading: string,
     *   level: int,
     *   body: string,
     *   emit_heading?: bool,
     *   parent_h2?: ?string
     * }>  $outlineNodes
     * @param  list<string>  $requiredPoints
     * @param  list<string>  $includedH3s
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
        public readonly ?string $parentH2 = null,
        public readonly bool $emitParentHeading = true,
        public readonly array $includedH3s = [],
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
            'parent_h2' => $this->parentH2,
            'emit_parent_heading' => $this->emitParentHeading,
            'included_h3s' => $this->includedH3s,
        ];
    }

    public function scopeMarkdown(): string
    {
        $parts = [];
        foreach ($this->outlineNodes as $node) {
            $level = max(1, min(3, (int) ($node['level'] ?? 2)));
            $heading = trim((string) ($node['heading'] ?? ''));
            $body = trim((string) ($node['body'] ?? ''));
            $emit = array_key_exists('emit_heading', $node)
                ? (bool) $node['emit_heading']
                : true;

            if (! $emit && $level <= 2) {
                // Continuation chunk — do not re-emit parent H2.
                if ($body !== '') {
                    $parts[] = $body;
                }
                continue;
            }

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
