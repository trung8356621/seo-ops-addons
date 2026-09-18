<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\System;

use App\System\Capability\SystemCapabilityHandler;

/**
 * Smoke capability proving System Agent path without CP gateway dependency.
 */
final class AgentEchoCapabilityHandler implements SystemCapabilityHandler
{
    public const KEY = 'agent.echo';

    public function handle(array $input, array $context = []): array
    {
        return [
            'echo' => (string) ($input['message'] ?? $input['text'] ?? ''),
            'runtime' => 'system_agent_capability',
            'content_project_required' => false,
        ];
    }
}
