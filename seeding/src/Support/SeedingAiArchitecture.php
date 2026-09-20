<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

/**
 * Architecture boundary: Seeding Gen Comment AI ≠ SEO Writing Prompt/Task/History UI.
 *
 * Seeding is a second domain consumer of shared System AI
 * (SystemAiClient → capability → AiTextExecutionPort / routing).
 * Manager prompt text + quota/link/share/report stay Seeding-owned.
 * Do not merge into SEO Prompt management screens.
 *
 * Canonical docs: docs/modules/SEEDING.md §8.
 */
final class SeedingAiArchitecture
{
    public const BOUNDARY = 'seeding_ai_independent_from_seo_prompt_task_history';

    public const RETENTION_MAX_LOGS = 20;

    public const MCP_CONTEXT_VAR = '{{mcp_context}}';
}
