<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\System;

use App\System\Agent\Contracts\AgentSkillContributor;

final class SeedingAgentSkillContributor implements AgentSkillContributor
{
    public function ownerSlug(): string
    {
        return 'seeding';
    }

    public function skills(): array
    {
        return [
            [
                'key' => 'seeding.comment.generate',
                'label' => 'Generate seeding comments',
                'capability' => SeedingCommentGenerateCapabilityHandler::KEY,
                'owner' => 'seeding',
                'description' => 'Generate social comments for seeding topics via System AI.',
            ],
        ];
    }
}
