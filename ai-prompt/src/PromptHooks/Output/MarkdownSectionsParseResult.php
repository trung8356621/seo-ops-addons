<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Output;

/**
 * Canonical markdown_sections parse result — no provider SDK objects.
 */
final class MarkdownSectionsParseResult
{
    /**
     * @param  array<string, string>  $sections  section key → content (no markers)
     * @param  array<string, string>  $ports  output_port → content (+ total)
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $ports,
        public readonly string $raw,
    ) {}

    /**
     * @return array{sections: array<string, string>, ports: array<string, string>, raw: string}
     */
    public function toArray(): array
    {
        return [
            'sections' => $this->sections,
            'ports' => $this->ports,
            'raw' => $this->raw,
        ];
    }
}
