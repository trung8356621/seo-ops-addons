<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

/**
 * Analysis result. Not a Topic, not a membership row, and not an apply command.
 *
 * analysisRef is reserved for a future out-of-process run. Null means the
 * proposal was produced inline. This task does not persist proposals.
 */
final class TopicGroupingProposal
{
    public const META_PROVIDER = 'provider';

    public const META_SCOPE = 'scope';

    /** Legacy engine counters. Optional. Absent for a non-legacy provider. */
    public const META_ENGINE_METRICS = 'engine_metrics';

    /**
     * @param  list<TopicGroupingGroup>  $groups
     * @param  list<TopicGroupingCandidate>  $unassigned
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $unassigned,
        public readonly array $metadata = [],
        public readonly ?string $analysisRef = null,
    ) {}
}
