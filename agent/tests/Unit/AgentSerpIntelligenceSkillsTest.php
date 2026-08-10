<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use PHPUnit\Framework\TestCase;

final class AgentSerpIntelligenceSkillsTest extends TestCase
{
    private AgentSkillRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new AgentSkillRegistry;
    }

    public function test_collect_serp_slash_command_and_capability(): void
    {
        $skill = $this->registry->get('serp.collect');

        self::assertNotNull($skill);
        self::assertSame('/collect-serp', $skill->slashCommand);
        self::assertSame('serp_intelligence.collect', $skill->capability);
        self::assertSame('serp_intelligence', $skill->category);
    }

    public function test_collect_serp_has_serp_provider_in_availability_policy(): void
    {
        $skill = $this->registry->get('serp.collect');

        self::assertNotNull($skill);
        self::assertSame('serp', $skill->availabilityPolicy['provider'] ?? null);
        self::assertSame('preview', $skill->confirmationPolicy);
    }

    public function test_collect_serp_resolves_via_slash_command(): void
    {
        $skill = $this->registry->resolveSlashCommand('/collect-serp');

        self::assertNotNull($skill);
        self::assertSame('serp.collect', $skill->key);
    }
}
