<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\Contracts;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInput;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingProposal;

/**
 * Topic grouping / analysis boundary.
 *
 * Implementations return an analysis proposal. They must not create, update,
 * delete, move, or lock Topic rows. Laravel Topic services remain authoritative
 * for identity, manual ownership, locks, and persistence.
 *
 * The return is synchronous so today's recluster job can stay unchanged.
 * A later provider may compute the proposal out of process and still return
 * this same object. Do not add transport, vector, or vendor types here.
 */
interface TopicGroupingProvider
{
    public function analyze(TopicGroupingInput $input): TopicGroupingProposal;
}
