<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use PHPUnit\Framework\TestCase;

final class AgentContentProjectSkillsTest extends TestCase
{
    private AgentSkillRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new AgentSkillRegistry;
    }

    public function test_create_project_slash_command_and_capability_mapping(): void
    {
        $skill = $this->registry->get('content_project.create');

        self::assertNotNull($skill);
        self::assertSame('/create-project', $skill->slashCommand);
        self::assertSame('content_project.create', $skill->capability);
        self::assertSame('content_project', $skill->category);
    }

    public function test_create_project_confirmation_policy_is_preview(): void
    {
        $skill = $this->registry->get('content_project.create');

        self::assertNotNull($skill);
        self::assertSame('preview', $skill->confirmationPolicy);
    }

    public function test_archive_project_confirmation_policy_is_confirm(): void
    {
        $skill = $this->registry->get('content_project.archive');

        self::assertNotNull($skill);
        self::assertSame('/archive-project', $skill->slashCommand);
        self::assertSame('content_project.archive', $skill->capability);
        self::assertSame('confirm', $skill->confirmationPolicy);
    }
}
