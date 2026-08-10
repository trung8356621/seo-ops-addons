<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillAvailability;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security\AgentPlanningOutputSanitizer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\DeterministicAgentPlanRepairer;
use PHPUnit\Framework\TestCase;

final class AgentPlanRepairerTest extends TestCase
{
    private DeterministicAgentPlanRepairer $repairer;

    protected function setUp(): void
    {
        parent::setUp();

        $skills = new AgentSkillRegistry([
            [
                'key' => 'content_project.create',
                'slash_command' => '/create-project',
                'aliases' => ['/tao-project'],
                'name' => 'Create',
                'description' => 'Create',
                'category' => 'content_project',
                'capability' => 'content_project.create',
                'availability_policy' => ['status_override' => AgentSkillAvailability::AVAILABLE],
                'form_schema' => [
                    ['key' => 'project_name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ],
            ],
        ]);

        $this->repairer = new DeterministicAgentPlanRepairer($skills, new AgentPlanningOutputSanitizer);
    }

    public function test_slash_alias_repaired_to_skill_key(): void
    {
        $result = $this->repairer->repair([
            'type' => 'single_intent',
            'confidence' => '0.91',
            'summary' => 'Create',
            'intent' => [
                'command' => '/create-project',
                'input' => ['name' => 'Aug'],
            ],
            'auto_execute' => true,
            'auto_confirm' => true,
        ]);

        self::assertSame('content_project.create', $result['response']->intent?->skillKey);
        self::assertArrayHasKey('project_name', $result['response']->intent?->input ?? []);
        self::assertContains('stripped:auto_execute', $result['repair_actions']);
        self::assertContains('stripped:auto_confirm', $result['repair_actions']);
        self::assertContains('normalize_confidence', $result['repair_actions']);
    }

    public function test_missing_indexes_repaired(): void
    {
        $result = $this->repairer->repair([
            'type' => 'execution_plan',
            'confidence' => 0.8,
            'summary' => 'Plan',
            'plan' => [
                'steps' => [
                    ['skill_key' => 'content_project.create', 'input' => []],
                    ['command' => '/tao-project', 'input' => []],
                ],
            ],
        ]);

        self::assertSame(AgentPlanningResponse::TYPE_EXECUTION_PLAN, $result['response']->type);
        self::assertCount(2, $result['response']->plan?->steps ?? []);
        self::assertSame(1, $result['response']->plan?->steps[0]->index);
        self::assertSame(2, $result['response']->plan?->steps[1]->index);
        self::assertSame('content_project.create', $result['response']->plan?->steps[1]->skillKey);
    }

    public function test_unknown_skill_not_invented(): void
    {
        $result = $this->repairer->repair([
            'type' => 'single_intent',
            'confidence' => 0.9,
            'summary' => 'x',
            'intent' => ['skill_key' => 'totally.fake'],
        ]);

        self::assertSame('totally.fake', $result['response']->intent?->skillKey);
    }
}
