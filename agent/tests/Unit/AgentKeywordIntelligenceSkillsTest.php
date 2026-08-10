<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use PHPUnit\Framework\TestCase;

final class AgentKeywordIntelligenceSkillsTest extends TestCase
{
    private AgentSkillRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new AgentSkillRegistry;
    }

    public function test_analyze_keywords_slash_command_and_capability(): void
    {
        $skill = $this->registry->get('keyword.analyze');

        self::assertNotNull($skill);
        self::assertSame('/analyze-keywords', $skill->slashCommand);
        self::assertSame('keyword_intelligence.analyze_workspace', $skill->capability);
        self::assertSame('keyword_intelligence', $skill->category);
    }

    public function test_build_topical_map_slash_command_and_capability(): void
    {
        $skill = $this->registry->get('keyword.build_topical_map');

        self::assertNotNull($skill);
        self::assertSame('/build-topical-map', $skill->slashCommand);
        self::assertSame('keyword_intelligence.build_topical_map', $skill->capability);
        self::assertSame('preview', $skill->confirmationPolicy);
    }

    public function test_keyword_skills_resolve_via_slash_commands(): void
    {
        self::assertSame('keyword.analyze', $this->registry->resolveSlashCommand('/analyze-keywords')?->key);
        self::assertSame('keyword.build_topical_map', $this->registry->resolveSlashCommand('/build-topical-map')?->key);
    }
}
