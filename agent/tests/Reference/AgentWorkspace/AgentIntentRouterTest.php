<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentChatTemplateRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentExecutionPlanService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentIntentRouter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceQuotaService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentIntentResolution;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectNaturalLanguageAdapter;
use PHPUnit\Framework\TestCase;

final class AgentIntentRouterTest extends TestCase
{
    private AgentIntentRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        $skills = new AgentSkillRegistry;
        $templates = new AgentChatTemplateRegistry;
        $plans = new AgentExecutionPlanService(new AgentWorkspaceQuotaService);

        $this->router = new AgentIntentRouter(
            $skills,
            $templates,
            new ContentProjectNaturalLanguageAdapter,
            $plans,
        );
    }

    public function test_exact_create_project_slash_returns_source_slash_confidence_one(): void
    {
        $resolution = $this->router->resolve('/create-project');

        self::assertSame('content_project.create', $resolution->skillKey);
        self::assertSame(AgentIntentResolution::SOURCE_SLASH, $resolution->source);
        self::assertSame(1.0, $resolution->confidence);
    }

    public function test_tao_project_alias_returns_source_alias(): void
    {
        $resolution = $this->router->resolve('/tao-project');

        self::assertSame('content_project.create', $resolution->skillKey);
        self::assertSame(AgentIntentResolution::SOURCE_ALIAS, $resolution->source);
    }

    public function test_template_key_create_project_month_returns_source_template(): void
    {
        $resolution = $this->router->resolve('', [
            'template_key' => 'create_project_month',
        ]);

        self::assertSame('content_project.create', $resolution->skillKey);
        self::assertSame(AgentIntentResolution::SOURCE_TEMPLATE, $resolution->source);
        self::assertSame(1.0, $resolution->confidence);
    }

    public function test_vietnamese_create_project_month_deterministic_match(): void
    {
        $resolution = $this->router->resolve('Tạo Content Project tháng 8');

        self::assertSame('content_project.create', $resolution->skillKey);
        self::assertSame(AgentIntentResolution::SOURCE_DETERMINISTIC, $resolution->source);
        self::assertGreaterThanOrEqual(0.55, $resolution->confidence);
    }

    public function test_multi_intent_returns_source_multi_with_plan_steps(): void
    {
        $resolution = $this->router->resolve('Phân tích từ khóa rồi tạo project');

        self::assertNull($resolution->skillKey);
        self::assertSame(AgentIntentResolution::SOURCE_MULTI, $resolution->source);
        self::assertNotNull($resolution->planSteps);
        self::assertNotEmpty($resolution->planSteps);
        self::assertTrue($resolution->requiresUserChoice);
    }

    public function test_low_confidence_ai_intent_requires_user_choice(): void
    {
        $resolution = $this->router->resolve('làm gì đó không rõ', [
            'ai_intent' => [
                'skill_key' => 'content_project.create',
                'confidence' => 0.2,
            ],
        ]);

        self::assertNull($resolution->skillKey);
        self::assertSame(AgentIntentResolution::SOURCE_LOW_CONFIDENCE, $resolution->source);
        self::assertTrue($resolution->requiresUserChoice);
        self::assertNotEmpty($resolution->candidateSkillKeys);
    }

    public function test_resolution_never_includes_auto_execute_flag(): void
    {
        $cases = [
            $this->router->resolve('/create-project'),
            $this->router->resolve('/tao-project'),
            $this->router->resolve('', ['template_key' => 'create_project_month']),
            $this->router->resolve('Tạo Content Project tháng 8'),
            $this->router->resolve('Phân tích từ khóa rồi tạo project'),
        ];

        foreach ($cases as $resolution) {
            $array = $resolution->toArray();
            self::assertArrayNotHasKey('auto_execute', $array);
            self::assertArrayNotHasKey('execute', $array);
        }
    }
}
