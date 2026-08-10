<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillAvailabilityService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceQuotaService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillAvailability;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedIntent;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedPlan;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedPlanStep;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services\DefaultAgentPlanValidator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class AgentPlanValidatorTest extends TestCase
{
    private DefaultAgentPlanValidator $validator;

    private AgentPlanningRequest $request;

    private AgentWorkspaceContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $skills = new AgentSkillRegistry([
            [
                'key' => 'content_project.create',
                'slash_command' => '/create-project',
                'name' => 'Create',
                'description' => 'Create',
                'category' => 'content_project',
                'capability' => 'content_project.create',
                'availability_policy' => ['status_override' => AgentSkillAvailability::AVAILABLE],
                'form_schema' => [
                    ['key' => 'project_name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['key' => 'month', 'label' => 'Month', 'type' => 'month', 'required' => true],
                ],
            ],
            [
                'key' => 'internal.secret',
                'slash_command' => '/internal',
                'name' => 'Internal',
                'description' => 'Hidden',
                'category' => 'internal',
                'capability' => 'agent.help',
                'is_hidden' => true,
                'availability_policy' => ['status_override' => AgentSkillAvailability::AVAILABLE],
            ],
        ]);

        $availability = new AgentSkillAvailabilityService(new CanonicalCapabilityRegistry(
            new ContentProjectCapabilityRegistry,
            new ExtensionCapabilityRegistry,
            new ExtensionStateStore,
        ));

        $this->validator = new DefaultAgentPlanValidator(
            $skills,
            $availability,
            new AgentWorkspaceQuotaService(maxMultiStepPlanActions: 3),
        );

        $this->context = new AgentWorkspaceContext(
            tenantRef: 't1',
            siteRef: 'site_a',
            tenantId: 1,
            siteId: 1,
            connectionId: 1,
            siteName: 'Site A',
            actorRef: 'u1',
            actorUserId: 1,
            role: 'manager',
        );

        $conversation = new SeoAgentConversation;
        $conversation->id = 1;
        $this->request = new AgentPlanningRequest(
            context: $this->context,
            conversation: $conversation,
            userMessage: 'Tạo project',
        );
    }

    public function test_valid_single_intent(): void
    {
        $response = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_SINGLE_INTENT,
            confidence: 0.9,
            summary: 'Create project',
            intent: new AgentProposedIntent('content_project.create', ['month' => '2026-08'], ['project_name']),
        );

        $result = $this->validator->validate($response, $this->request, $this->context);

        self::assertTrue($result->ok);
    }

    public function test_valid_plan(): void
    {
        $response = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_EXECUTION_PLAN,
            confidence: 0.86,
            summary: 'Multi',
            plan: new AgentProposedPlan([
                new AgentProposedPlanStep(1, 'content_project.create', [], [], ['project_ref' => 'context.project_ref']),
                new AgentProposedPlanStep(2, 'content_project.create', ['project_name' => 'x'], [1]),
            ]),
        );

        $result = $this->validator->validate($response, $this->request, $this->context);

        self::assertTrue($result->ok);
    }

    public function test_unknown_skill_rejected(): void
    {
        $response = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_SINGLE_INTENT,
            confidence: 0.9,
            summary: 'x',
            intent: new AgentProposedIntent('does.not.exist'),
        );

        $result = $this->validator->validate($response, $this->request, $this->context);

        self::assertFalse($result->ok);
        self::assertNotEmpty(array_filter(
            $result->errors,
            static fn (string $e): bool => str_starts_with($e, 'unknown_skill'),
        ));
    }

    public function test_internal_skill_rejected(): void
    {
        $response = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_SINGLE_INTENT,
            confidence: 0.9,
            summary: 'x',
            intent: new AgentProposedIntent('internal.hack'),
        );

        $result = $this->validator->validate($response, $this->request, $this->context);

        self::assertFalse($result->ok);
        self::assertContains('internal_skill_forbidden', $result->errors);
    }

    public function test_extra_input_field_rejected(): void
    {
        $response = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_SINGLE_INTENT,
            confidence: 0.9,
            summary: 'x',
            intent: new AgentProposedIntent('content_project.create', ['evil_field' => '1', 'month' => '2026-08']),
        );

        $result = $this->validator->validate($response, $this->request, $this->context);

        self::assertFalse($result->ok);
        self::assertContains('extra_input_field:evil_field', $result->errors);
    }

    public function test_cross_site_ref_rejected(): void
    {
        $response = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_SINGLE_INTENT,
            confidence: 0.9,
            summary: 'x',
            intent: new AgentProposedIntent('content_project.create', [
                'month' => '2026-08',
                'site_ref' => 'other_site',
            ]),
        );

        $result = $this->validator->validate($response, $this->request, $this->context);

        self::assertFalse($result->ok);
        self::assertTrue(
            in_array('cross_site_reference', $result->errors, true)
            || in_array('forbidden_input_field:site_ref', $result->errors, true)
        );
    }

    public function test_future_dependency_and_too_many_steps_rejected(): void
    {
        $response = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_EXECUTION_PLAN,
            confidence: 0.9,
            summary: 'x',
            plan: new AgentProposedPlan([
                new AgentProposedPlanStep(1, 'content_project.create', [], [2]),
                new AgentProposedPlanStep(2, 'content_project.create'),
                new AgentProposedPlanStep(3, 'content_project.create'),
                new AgentProposedPlanStep(4, 'content_project.create'),
            ]),
        );

        $result = $this->validator->validate($response, $this->request, $this->context);

        self::assertFalse($result->ok);
        self::assertContains('future_dependency', $result->errors);
        self::assertContains('too_many_steps', $result->errors);
    }

    public function test_forbidden_output_binding_rejected(): void
    {
        $response = new AgentPlanningResponse(
            type: AgentPlanningResponse::TYPE_EXECUTION_PLAN,
            confidence: 0.9,
            summary: 'x',
            plan: new AgentProposedPlan([
                new AgentProposedPlanStep(1, 'content_project.create', [], [], ['api_key' => 'x']),
            ]),
        );

        $result = $this->validator->validate($response, $this->request, $this->context);

        self::assertFalse($result->ok);
        self::assertContains('output_binding_forbidden:api_key', $result->errors);
    }
}
