<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

/**
 * Architecture boundary: Seeding Gen Comment execution uses shared System AI + shared Prompt SSOT.
 *
 * Prompt body / versions / Prompt UI = ai-prompt Prompt management.
 * Seeding keeps MCP context, quota, link, share, report, and domain history ring.
 * Do not restore seeding_comment_prompt_settings as execution authority.
 *
 * Canonical docs: docs/modules/SEEDING.md §8.
 */
final class SeedingAiArchitecture
{
    public const BOUNDARY = 'seeding_ai_uses_shared_prompt_system_ai';

    /** @deprecated Historical marker kept for migration notes only */
    public const LEGACY_BOUNDARY = 'seeding_ai_independent_from_seo_prompt_task_history';

    public const RETENTION_MAX_LOGS = 20;

    public const MCP_CONTEXT_VAR = '{{mcp_context}}';
}
