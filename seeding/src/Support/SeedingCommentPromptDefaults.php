<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

/**
 * Default Manager Gen Comment prompt — migrated from legacy Social task style instructions.
 */
final class SeedingCommentPromptDefaults
{
    public const MCP_CONTEXT_VAR = SeedingAiArchitecture::MCP_CONTEXT_VAR;

    public static function promptBody(): string
    {
        return <<<'PROMPT'
You generate short, natural Vietnamese social comments based only on the supplied text context.

Requirements:
- Write in Vietnamese.
- Match the supplied context.
- Adapt naturally to the supplied social platform.
- Do not invent unsupported facts.
- Use varied wording and sentence structure.
- Avoid obvious repeated patterns.
- Avoid sounding like spam or forced advertising.
- Keep each comment concise.
- Each comment must be under 300 words.

{{mcp_context}}
PROMPT;
    }
}
