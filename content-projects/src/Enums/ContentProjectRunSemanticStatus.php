<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Semantic run lifecycle for ContentProjectRunEngine.
 * Mapped to legacy seo_project_runs.status via ContentProjectRunStatusMapper.
 */
enum ContentProjectRunSemanticStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Stopping = 'stopping';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Completed, self::Failed => true,
            default => false,
        };
    }

    public function allowsDispatch(): bool
    {
        return $this === self::Running;
    }

    public function isStopRequested(): bool
    {
        return $this === self::Stopping || $this === self::Cancelled;
    }
}
