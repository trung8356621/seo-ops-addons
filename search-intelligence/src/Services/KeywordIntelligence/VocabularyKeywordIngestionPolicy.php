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

    /**
     * Phase 7 safest default: Related topics only.
     *
     * @var array<string, bool>
     */
    private const ENABLED = [
        self::GROUP_RELATED_TOPICS => true,
        self::GROUP_LONG_TAIL => false,
        self::GROUP_SEMANTIC => false,
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
