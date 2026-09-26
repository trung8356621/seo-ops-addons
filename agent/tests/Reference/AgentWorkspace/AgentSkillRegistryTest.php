<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills\BuiltinSkillCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentSkillRegistryTest extends TestCase
{
    public function test_boots_builtin_skill_catalog(): void
    {
        $registry = new AgentSkillRegistry;
        $skills = $registry->all();

        self::assertNotEmpty($skills);
        self::assertNotNull($registry->get('content_project.create'));
        self::assertGreaterThanOrEqual(count(BuiltinSkillCatalog::definitions()), count($registry->all(includeHidden: true)));
    }

    public function test_canonical_slash_commands_are_unique(): void
    {
        $registry = new AgentSkillRegistry;
        $commands = [];

        foreach ($registry->all(includeHidden: true) as $skill) {
            $canonical = mb_strtolower($skill->slashCommand);
            self::assertNotContains($canonical, $commands, 'Duplicate slash command: '.$canonical);
            $commands[] = $canonical;
        }
    }

    public function test_aliases_new_project_and_tao_project_resolve_to_create(): void
    {
        $registry = new AgentSkillRegistry;

        $viaNew = $registry->resolveSlashCommand('/new-project');
        $viaTao = $registry->resolveSlashCommand('/tao-project');

        self::assertNotNull($viaNew);
        self::assertNotNull($viaTao);
        self::assertSame('content_project.create', $viaNew->key);
        self::assertSame('content_project.create', $viaTao->key);
    }

    public function test_conflict_definitions_throw_runtime_exception(): void
    {
        $registry = new AgentSkillRegistry([
            [
                'key' => 'skill.a',
                'slash_command' => '/duplicate-cmd',
                'name' => 'A',
                'description' => 'A',
                'category' => 'general',
                'capability' => 'agent.help',
            ],
            [
                'key' => 'skill.b',
                'slash_command' => '/duplicate-cmd',
                'name' => 'B',
                'description' => 'B',
                'category' => 'general',
                'capability' => 'agent.help',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('agent.skill_command_conflict');

        $registry->all();
    }

    public function test_hidden_skills_excluded_from_all_by_default(): void
    {
        $registry = new AgentSkillRegistry([
            [
                'key' => 'visible.skill',
                'slash_command' => '/visible-skill',
                'name' => 'Visible',
                'description' => 'Visible skill',
                'category' => 'general',
                'capability' => 'agent.help',
            ],
            [
                'key' => 'hidden.skill',
                'slash_command' => '/hidden-skill',
                'name' => 'Hidden',
                'description' => 'Hidden skill',
                'category' => 'general',
                'capability' => 'agent.help',
                'is_hidden' => true,
            ],
        ]);

        $visibleKeys = array_map(static fn ($s) => $s->key, $registry->all());
        $allKeys = array_map(static fn ($s) => $s->key, $registry->all(includeHidden: true));

        self::assertContains('visible.skill', $visibleKeys);
        self::assertNotContains('hidden.skill', $visibleKeys);
        self::assertContains('hidden.skill', $allKeys);
    }

    public function test_featured_skills_include_create_project(): void
    {
        $registry = new AgentSkillRegistry;
        $featuredKeys = array_map(static fn ($s) => $s->key, $registry->featured());

        self::assertContains('content_project.create', $featuredKeys);
    }

    public function test_categories_include_expected_groups(): void
    {
        $registry = new AgentSkillRegistry;
        $categories = array_unique(array_map(static fn ($s) => $s->category, $registry->all()));

        foreach (['content_project', 'keyword_intelligence', 'serp_intelligence', 'operations', 'general'] as $expected) {
            self::assertContains($expected, $categories, "Missing category: {$expected}");
        }
    }
}
