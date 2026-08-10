<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Vòng đời review của một cannibalization issue.
 * Stale = không còn phát hiện thấy ở lần detect gần nhất (đã tự hết hoặc dữ liệu thay đổi).
 */
enum KeywordCannibalizationIssueStatus: string
{
    case Open = 'open';
    case Reviewed = 'reviewed';
    case Ignored = 'ignored';
    case Resolved = 'resolved';
    case Stale = 'stale';
}
