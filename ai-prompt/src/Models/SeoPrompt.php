<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Models;

use Omnichannel\Addons\AiPrompt\Support\PromptMarkdownParser;
use Illuminate\Support\Collection;

/**
 * Prompt SEO trên DB addon (`prompts`, connection `omi_seo_ai`).
 */
class SeoPrompt extends Prompt
{
    protected static function booted(): void
    {
        static::saving(function (self $prompt): void {
            $settings = is_array($prompt->settings) ? $prompt->settings : [];
            $settings['detected_tags'] = app(\Omnichannel\Addons\ContentProjects\Services\WorkflowTagExtractorService::class)
                ->detectTagsFromPromptTemplate((string) ($prompt->markdown_content ?? ''));
            $prompt->settings = $settings;
        });
    }

    protected $casts = [
        'settings' => 'array',
        'variables' => 'json',
        'hook_settings' => 'array',
        'hook_version' => 'string',
        'is_active' => 'boolean',
        'markdown_content' => 'string',
    ];

    /**
     * @return Collection<int, SeoPromptPart>
     */
    public function getVirtualPartsAttribute(): Collection
    {
        $partsData = PromptMarkdownParser::parse((string) ($this->markdown_content ?? ''));
        if ($partsData === []) {
            return collect();
        }

        return collect($partsData)
            ->map(static function (array $data): SeoPromptPart {
                $part = new SeoPromptPart();
                $part->forceFill($data);

                return $part;
            });
    }

    /**
     * Các block prompt parse từ markdown_content (không còn bảng prompt_parts).
     *
     * @return Collection<int, SeoPromptPart>
     */
    public function resolvedParts(): Collection
    {
        return $this->virtual_parts->values();
    }
}
