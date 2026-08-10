<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

/**
 * How a prompt block chooses AI execution.
 * Global migration mode stays legacy — ExplicitBinding is per saved prompt only.
 */
enum PromptHookExecutionIntent: string
{
    case LegacyDefault = 'legacy_default';
    case ExplicitBinding = 'explicit_hook_binding';
}
