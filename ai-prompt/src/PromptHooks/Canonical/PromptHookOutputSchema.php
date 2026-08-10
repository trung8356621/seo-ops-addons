<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

final class PromptHookOutputSchema
{
    /**
     * @param  list<string>  $normalize
     * @param  array<string, mixed>  $validation
     * @param  list<array<string, mixed>>  $sections
     */
    public function __construct(
        public readonly string $type,
        public readonly array $normalize = [],
        public readonly array $validation = [],
        public readonly array $sections = [],
        public readonly string $totalPort = 'total',
    ) {}

    public function isMarkdownSections(): bool
    {
        return $this->type === 'markdown_sections';
    }

    public function allowTextOutsideSections(): bool
    {
        return (bool) ($this->validation['allow_text_outside_sections'] ?? false);
    }

    public function strictUndeclaredMarkers(): bool
    {
        if (array_key_exists('strict_undeclared_markers', $this->validation)) {
            return (bool) $this->validation['strict_undeclared_markers'];
        }

        if (array_key_exists('reject_unknown_task_markers', $this->validation)) {
            return (bool) $this->validation['reject_unknown_task_markers'];
        }

        return true;
    }

    public function preserveMarkersInTotal(): bool
    {
        return (bool) ($this->validation['preserve_markers_in_total'] ?? true);
    }
}
