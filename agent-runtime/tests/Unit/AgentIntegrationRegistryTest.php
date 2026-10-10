<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Integration\AgentIntegrationRegistry;
use Omnichannel\Addons\AgentRuntime\Integration\AgentOperationHandlerRegistry;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Omnichannel\Addons\AgentRuntime\Integration\RoutingReviewChoiceValidator;

final class AgentIntegrationRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        AgentCapabilityCatalog::unregister('demo.status');
        parent::tearDown();
    }

    private function registerDemoCapability(): void
    {
        AgentCapabilityCatalog::register('demo.status', [
            'label' => 'Demo Status',
            'description' => 'Read synthetic service status.',
            'jev_selectable' => true,
            'execution_mode' => 'direct',
            'requires_confirmation' => false,
            'status' => 'available',
            'modules' => [],
        ]);
    }

    public function test_synthetic_service_registers_cases_and_dispatch_requires_explicit_handler(): void
    {
        $this->registerDemoCapability();
        $base = dirname(__DIR__).'/Fixtures/demo/agent';
        $registry = new AgentIntegrationRegistry();
        $registry->register('demo', $base.'/routing.json', $base.'/cases.json');

        self::assertArrayHasKey('demo', $registry->document()['modules']);
        self::assertSame('demo.read', $registry->cases('demo')[0]['expected_operation']);

        $handlers = new AgentOperationHandlerRegistry();
        $this->expectException(RuntimeException::class);
        $handlers->dispatch('demo.status');
    }

    public function test_synthetic_handler_dispatches_without_core_module_branch(): void
    {
        $this->registerDemoCapability();
        $handlers = new AgentOperationHandlerRegistry();
        $handlers->register('demo.status', static fn (): string => 'demo-ok');
        self::assertSame('demo-ok', $handlers->dispatch('demo.status'));
    }

    public function test_duplicate_service_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $registry = new AgentIntegrationRegistry();
        $registry->register('demo', 'a', 'b');
        $registry->register('demo', 'a', 'b');
    }

    public function test_routing_review_accepts_selected_alternative_and_none_but_rejects_fabrication(): void
    {
        $snapshot = [
            'selected_candidate_id' => 'candidate-a',
            'candidates' => [
                ['id' => 'candidate-a', 'question' => 'Question A'],
                ['id' => 'candidate-b', 'question' => 'Question B'],
            ],
        ];
        $validator = new RoutingReviewChoiceValidator();

        self::assertSame('candidate-a', $validator->validate($snapshot, 'candidate-a', false)['preferred_candidate_id']);
        self::assertSame('candidate-b', $validator->validate($snapshot, 'candidate-b', false)['preferred_candidate_id']);
        self::assertTrue($validator->validate($snapshot, null, true)['none_of_above']);

        $this->expectException(InvalidArgumentException::class);
        $validator->validate($snapshot, 'fabricated', false);
    }

    public function test_feedback_ingestion_resolves_only_owned_completed_runs(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Http/AgentRoutingFeedbackController.php');

        self::assertIsString($source);
        self::assertStringContainsString("->where('user_id', \$userId)", $source);
        self::assertStringContainsString("->where('status', 'done')", $source);
        self::assertStringContainsString('RoutingReviewChoiceValidator', $source);
    }
}
