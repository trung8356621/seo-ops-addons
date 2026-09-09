<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Normalized failure categories for AI execution observability.
 */
enum AiFailureCategory: string
{
    case Routing = 'ROUTING';
    case Provider = 'PROVIDER';
    case Validation = 'VALIDATION';
    case System = 'SYSTEM';
    case Workflow = 'WORKFLOW';
}
