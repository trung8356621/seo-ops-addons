<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Support;

/**
 * Permanent architecture boundary: Seeding Gen Comment AI ≠ SEO AI Prompt/Task/History.
 *
 * Do not design Seeding AI for a future merge into the SEO prompt pipeline.
 * Prefer Seeding-local duplication over coupling to the stable SEO AI system.
 *
 * Canonical docs: docs/modules/SEEDING.md §8.
 */
final class SeedingAiArchitecture
{
    public const BOUNDARY = 'seeding_ai_independent_from_seo_prompt_task_history';

    public const RETENTION_MAX_LOGS = 20;

    public const MCP_CONTEXT_VAR = '{{mcp_context}}';
}
