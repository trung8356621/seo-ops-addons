<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Enums;

enum MatchResearchKind: string
{
    case Concept = 'concept';
    case RuleSet = 'rule_set';
    case Ambiguity = 'ambiguity';
}
