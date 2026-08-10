<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Semantic article-execution status inside a Content Project run.
 * Mapped to seo_project_run_items.status via ContentProjectRunStatusMapper.
 */
enum ContentProjectArticleSemanticStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled, self::Skipped => true,
            default => false,
        };
    }
}
