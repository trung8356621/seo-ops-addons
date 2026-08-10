<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Topical Map skills trong Keyword Intelligence catalog.
 */
final class AgentTopicalMapSkillsTest extends TestCase
{
    private AgentSkillRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new AgentSkillRegistry;
    }

    public function test_build_topical_map_skill_exists_in_catalog(): void
    {
        $skill = $this->registry->get('keyword.build_topical_map');

        self::assertNotNull($skill);
        self::assertSame('/build-topical-map', $skill->slashCommand);
    }

    public function test_build_topical_map_capability_mapping(): void
    {
        $skill = $this->registry->get('keyword.build_topical_map');

        self::assertNotNull($skill);
        self::assertSame('keyword_intelligence.build_topical_map', $skill->capability);
        self::assertSame('keyword_intelligence', $skill->category);
    }

    public function test_build_topical_map_confirmation_policy_is_preview(): void
    {
        $skill = $this->registry->get('keyword.build_topical_map');

        self::assertNotNull($skill);
        self::assertSame('preview', $skill->confirmationPolicy);
        self::assertContains('workspace_ref', $skill->availabilityPolicy['requires_context'] ?? []);
    }

    public function test_approve_topical_map_requires_confirm(): void
    {
        $skill = $this->registry->get('keyword.approve_topical_map');

        self::assertNotNull($skill);
        self::assertSame('/approve-topical-map', $skill->slashCommand);
        self::assertSame('keyword_intelligence.approve_topical_map', $skill->capability);
        self::assertSame('confirm', $skill->confirmationPolicy);
    }
}
