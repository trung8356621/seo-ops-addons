<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Serp;

enum SerpContentGapType: string
{
    case MissingEntity = 'missing_entity';
    case MissingSubtopic = 'missing_subtopic';
    case MissingQuestion = 'missing_question';
    case MissingHeading = 'missing_heading';
    case MissingSchema = 'missing_schema';
    case MissingMedia = 'missing_media';
    case MissingComparison = 'missing_comparison';
    case FreshnessGap = 'freshness_gap';
    case FormatGap = 'format_gap';
    case IntentMismatch = 'intent_mismatch';
    case PageTypeMismatch = 'page_type_mismatch';
    case WeakCoverage = 'weak_coverage';
}
