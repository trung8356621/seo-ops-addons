<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Runtime;

/**
 * Write boundary reserved for a later connection.
 * V1 does not call the draft intake endpoint.
 */
final class ContentProjectDraftIntakeTool
{
    public const ACTION = 'content_project.draft.intake';

    public function isConnected(): bool
    {
        return false;
    }
}
