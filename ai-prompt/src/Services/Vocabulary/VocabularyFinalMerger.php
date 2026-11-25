<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Vocabulary;

use Omnichannel\Addons\AiPrompt\Support\Vocabulary\VocabularyMarkdownCodec;

/**
 * Internal-first merge; external (AI) only gap-fills empty slots — no invented business facts.
 */
final class VocabularyFinalMerger
{
    public function __construct(
        private readonly VocabularyMarkdownCodec $codec = new VocabularyMarkdownCodec,
    ) {}

    /**
     * @param  array<string, list<string>>  $internal
     * @param  array<string, list<string>>  $external
     * @return array<string, list<string>>
     */
    public function merge(array $internal, array $external): array
    {
        $headings = array_unique([
            ...VocabularyMarkdownCodec::CANONICAL_GROUP_HEADINGS,
            ...array_keys($internal),
            ...array_keys($external),
        ]);

        $merged = [];
        foreach ($headings as $heading) {
            $primary = $this->codec->dedupeItems($internal[$heading] ?? []);
            $secondary = $this->codec->dedupeItems($external[$heading] ?? []);

            $seen = [];
            foreach ($primary as $item) {
                $seen[mb_strtolower($item)] = true;
            }

            $combined = $primary;
            foreach ($secondary as $item) {
                $key = mb_strtolower($item);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $combined[] = $item;
                if (count($combined) >= VocabularyMarkdownCodec::ITEMS_PER_GROUP) {
                    break;
                }
            }

            if ($combined !== []) {
                $merged[$heading] = array_slice($combined, 0, VocabularyMarkdownCodec::ITEMS_PER_GROUP);
            }
        }

        return $merged;
    }
}