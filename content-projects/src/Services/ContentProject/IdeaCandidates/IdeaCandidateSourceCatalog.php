<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates;

/**
 * Simple source registry — add GSC MCP later without rewriting picker layout.
 */
final class IdeaCandidateSourceCatalog
{
    /**
     * @return list<IdeaCandidateSource>
     */
    public function all(): array
    {
        return [
            new IdeaCandidateSource(
                IdeaCandidateSource::KEY_VOCABULARY_SUGGEST,
                $this->vocabularySuggestLabel(),
            ),
        ];
    }

    private function vocabularySuggestLabel(): string
    {
        try {
            $label = (string) __('seo-content-ai::filament.keyword.keyword_item_tag_vocabulary_suggest');
            if (
                $label !== ''
                && $label !== 'seo-content-ai::filament.keyword.keyword_item_tag_vocabulary_suggest'
            ) {
                return $label;
            }
        } catch (\Throwable) {
            // Pure PHPUnit / no translator.
        }

        return 'Vocabulary Suggest';
    }

    public function find(string $key): ?IdeaCandidateSource
    {
        foreach ($this->all() as $source) {
            if ($source->key === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function options(): array
    {
        return array_map(
            static fn (IdeaCandidateSource $s): array => [
                'key' => $s->key,
                'label' => $s->label,
            ],
            $this->all(),
        );
    }
}
