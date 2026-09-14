<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

/**
 * Only {{mcp_context}} is replaced. No other template variables.
 */
final class SeedingCommentPromptRenderer
{
    public function render(string $managerPrompt, string $mcpContext): string
    {
        return str_replace(
            SeedingCommentPromptDefaults::MCP_CONTEXT_VAR,
            $mcpContext,
            $managerPrompt,
        );
    }

    /**
     * @return list<string>
     */
    public function supportedVariables(): array
    {
        return [SeedingCommentPromptDefaults::MCP_CONTEXT_VAR];
    }
}
