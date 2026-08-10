<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data;

final readonly class AgentKnowledgeConflictResult
{
    public const RESOLVED = 'resolved';

    public const SCOPE_OVERRIDE = 'scope_override';

    public const SOURCE_SUPERSEDED = 'source_superseded';

    public const REQUIRES_USER_REVIEW = 'requires_user_review';

    public const UNRESOLVED = 'unresolved';

    /**
     * @param  list<string>  $itemRefs
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $status,
        public string $summary,
        public array $itemRefs = [],
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'summary' => $this->summary,
            'item_refs' => $this->itemRefs,
            'meta' => $this->meta,
        ];
    }
}
