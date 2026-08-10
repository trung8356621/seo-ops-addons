<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums;

enum SerpIntentReconciliationCode: string
{
    case Consistent = 'serp.intent_consistent';
    case Mismatch = 'serp.intent_mismatch';
    case Mixed = 'serp.intent_mixed';
    case InsufficientEvidence = 'serp.intent_insufficient_evidence';
}
