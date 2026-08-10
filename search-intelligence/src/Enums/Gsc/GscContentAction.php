<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscContentAction: string
{
    case Keep = 'keep';
    case Improve = 'improve';
    case Rewrite = 'rewrite';
    case Differentiate = 'differentiate';
    case Consolidate = 'consolidate';
    case WriteNew = 'write_new';
    case NeedsReview = 'needs_review';
    case Blocked = 'blocked';
}
