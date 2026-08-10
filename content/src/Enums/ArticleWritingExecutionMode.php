<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Enums;

/**
 * Cách chạy article writing — không trộn mode.
 *
 * - PublishGraph: first-run / CREATE (outline node rồi content).
 * - ContentNode: CP «Tạo lại bài từ dàn ý» (không chạy lại outline).
 * - DirectGenerate: Editor full rewrite (settings-owned, không Publish graph).
 */
enum ArticleWritingExecutionMode: string
{
    case PublishGraph = 'publish_graph';
    case ContentNode = 'content_node';
    case DirectGenerate = 'direct_generate';
}
