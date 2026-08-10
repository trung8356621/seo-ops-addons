<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Loại node trong topical map.
 */
enum KeywordTopicType: string
{
    case Root = 'root';
    case Pillar = 'pillar';
    case Subtopic = 'subtopic';
    /** @deprecated Giữ để tương thích dữ liệu cũ — topical map mới dùng ClusterGroup. */
    case Cluster = 'cluster';
    case ClusterGroup = 'cluster_group';
    case FaqGroup = 'faq_group';
}
