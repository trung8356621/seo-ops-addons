<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Dto;

/**
 * Lazy Topic children for Topical Map drill-down.
 *
 * @phpstan-type ChildNode array{
 *   type: string,
 *   id: int,
 *   name: string,
 *   article_count: int
 * }
 */
final class TopicalMapTopicChildren
{
    public const MAX_CHILDREN = 100;

    /**
     * @param  list<ChildNode>  $children
     */
    public function __construct(
        public readonly int $siteId,
        public readonly int $topicId,
        public readonly string $topicName,
        public readonly array $children,
        public readonly int $total,
        public readonly bool $truncated,
    ) {}

    /**
     * @return array{
     *   site_id: int,
     *   topic_id: int,
     *   topic_name: string,
     *   children: list<ChildNode>,
     *   total: int,
     *   truncated: bool,
     *   showing: int
     * }
     */
    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'topic_id' => $this->topicId,
            'topic_name' => $this->topicName,
            'children' => $this->children,
            'total' => $this->total,
            'truncated' => $this->truncated,
            'showing' => count($this->children),
        ];
    }
}
