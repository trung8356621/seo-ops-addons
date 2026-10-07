<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Analysis scope for {@see TopicGroupingProvider}.
 *
 * Both scopes are part of the replaceable contract. A future provider must
 * answer the same scopes; consumers should not grow a second grouping entry.
 */
final class TopicGroupingScope
{
    /** Full site grouping from seeds, candidates, and protected context. */
    public const SITE_RECLUSTER = 'site_recluster';

    /**
     * Match candidates against one existing Topic label.
     * Used by manual create and explicit rescan. Not a site rebuild.
     */
    public const TOPIC_MEMBERSHIP_SCAN = 'topic_membership_scan';
}
