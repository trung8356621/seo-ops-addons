<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/** Latest execution attempt (generate/rerun/publish attempt) — independent of published revision. */
enum ContentProjectItemExecutionState: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Failed = 'failed';
    case Succeeded = 'succeeded';
}
