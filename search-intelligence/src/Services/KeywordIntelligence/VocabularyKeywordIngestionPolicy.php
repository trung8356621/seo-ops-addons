<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

/**
 * Centralized Vocabulary → KI group policy (no Settings UI).
 */
final class VocabularyKeywordIngestionPolicy
{
    public const GROUP_RELATED_TOPICS = 'related_topics';

    public const GROUP_LONG_TAIL = 'long_tail_keywords';

    public const GROUP_SEMANTIC = 'semantic_keywords';

    public const GROUP_SEMANTIC_ENTITIES = 'semantic_entities';

    public const GROUP_RELEVANT_ENTITIES = 'relevant_entities';

    public const GROUP_HOLONYMY = 'holonymy';

    public const GROUP_RELATIONAL_ENTITIES = 'relational_entities';

    public const GROUP_CLOSE_ENTITIES = 'close_entities';

    public const GROUP_SALIENT_ENTITIES = 'salient_entities';

    public const GROUP_SALIENT_KEYWORDS = 'salient_keywords';

    /**
     * Strong + supporting Vocabulary groups → Suggest pool.
     * Synonyms / Antonyms / N-grams / LSI legacy stay out.
     *
     * @var array<string, bool>
     */
    private const ENABLED = [
        self::GROUP_RELATED_TOPICS => true,
        self::GROUP_LONG_TAIL => true,
        self::GROUP_SEMANTIC => true,
        self::GROUP_SEMANTIC_ENTITIES => true,
        self::GROUP_RELEVANT_ENTITIES => true,
        self::GROUP_HOLONYMY => true,
        self::GROUP_RELATIONAL_ENTITIES => true,
        self::GROUP_CLOSE_ENTITIES => true,
        self::GROUP_SALIENT_ENTITIES => true,
        self::GROUP_SALIENT_KEYWORDS => true,
    ];

    public function isEnabled(string $canonicalGroup): bool
    {
        return (bool) (self::ENABLED[$canonicalGroup] ?? false);
    }

    /**
     * Map raw Vocabulary heading → canonical group key, or null if unknown/disabled path.
     */
    public function resolveCanonicalGroup(string $groupName): ?string
    {
        $name = $this->normalizeHeading($groupName);

        return match (true) {
            $name === 'related topics' => self::GROUP_RELATED_TOPICS,
            $name === 'long-tail keywords', $name === 'long tail keywords' => self::GROUP_LONG_TAIL,
            $name === 'semantic keywords' => self::GROUP_SEMANTIC,
            $name === 'semantic entities' => self::GROUP_SEMANTIC_ENTITIES,
            $name === 'relevant entities' => self::GROUP_RELEVANT_ENTITIES,
            $name === 'holonymy' => self::GROUP_HOLONYMY,
            $name === 'relational entities' => self::GROUP_RELATIONAL_ENTITIES,
            $name === 'close entities' => self::GROUP_CLOSE_ENTITIES,
            $name === 'salient entities' => self::GROUP_SALIENT_ENTITIES,
            $name === 'salient keywords' => self::GROUP_SALIENT_KEYWORDS,
            default => null,
        };
    }

    public function normalizeHeading(string $groupName): string
    {
        $name = trim($groupName);
        $name = preg_replace('/^#+\s*/u', '', $name) ?? $name;
        $name = trim(str_replace(['**', '*'], '', $name));

        return mb_strtolower($name);
    }

    /**
     * Site-scoped keyword_meta suffix for vocabulary discovery provenance (not coverage).
     */
    public function evidenceMetaSuffix(string $canonicalGroup): string
    {
        return 'vocab_evidence.'.$canonicalGroup;
    }
}
