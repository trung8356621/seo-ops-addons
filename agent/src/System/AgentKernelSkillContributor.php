<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\System;

use App\System\Agent\Contracts\AgentSkillContributor;

/**
 * Kernel skills owned by Agent addon (non-domain). Domain catalogs register from their addons.
 */
final class AgentKernelSkillContributor implements AgentSkillContributor
{
    public function ownerSlug(): string
    {
        return 'agent';
    }

    public function skills(): array
    {
        return [
            [
                'key' => 'agent.echo',
                'label' => 'Echo',
                'capability' => 'agent.echo',
                'owner' => 'agent',
                'description' => 'System Agent smoke skill — no Content Project dependency.',
            ],
        ];
    }
}
