<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Vocabulary;

use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Support\Vocabulary\VocabularyMarkdownCodec;

/**
 * Heuristic sufficiency — no hard internal/external ratio.
 */
final class VocabularySufficiencyGate
{
    /** @var list<string> */
    private const ANCHOR_GROUPS = [
        'Long-tail keywords',
        'Semantic keywords',
        'Related topics',
        'Holonymy',
        'Synonyms',
    ];

    public function __construct(
        private readonly VocabularyMarkdownCodec $codec = new VocabularyMarkdownCodec,
    ) {}

    /**
     * @param  array<string, list<string>>  $groups
     */
    public function isSufficient(array $groups): bool
    {
        $rendered = $this->codec->render($groups);
        if (mb_strlen($rendered) < ArticleOutlineVocabularySplitExecutor::MIN_DIRECT_BODY_LENGTH) {
            return false;
        }

        $filledAnchors = 0;
        foreach (self::ANCHOR_GROUPS as $heading) {
            $items = $groups[$heading] ?? [];
            if (count($items) >= 2) {
                $filledAnchors++;
            }
        }

        return $filledAnchors >= 4;
    }
}