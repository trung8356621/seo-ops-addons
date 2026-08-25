<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

/**
 * Centralized caps for Planning Intelligence context (no AI calls).
 */
final class ContentPlanningIntelligenceCaps
{
    public const PRINCIPAL_KEYWORDS = 100;

    public const CLUSTERS = 30;

    public const EXISTING_TOPICS = 100;

    public const PLANNED_TOPICS = 100;

    public const REJECTED_TOPICS = 100;

    public const MCP_SIGNALS = 30;

    public const GSC_SIGNALS = 30;

    public const MISSING_DIRECTIONS = 30;

    public const MIN_KEYWORD_SCORE = 0.45;

    /** @var list<string> */
    public const EXCLUDED_PHRASE_KINDS = [
        'sentence',
        'noise',
        'url_domain',
        'descriptive_phrase',
    ];
}
