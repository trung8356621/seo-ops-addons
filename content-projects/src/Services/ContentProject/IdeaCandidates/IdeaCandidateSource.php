<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaCandidates;

/**
 * Extensible planner idea source contract (phase 1: Vocabulary Suggest only).
 */
final class IdeaCandidateSource
{
    public const KEY_VOCABULARY_SUGGEST = 'vocabulary_suggest';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {}
}
