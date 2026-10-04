<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExactModelFastPathContractTest extends TestCase
{
    private string $router;

    private string $targets;

    protected function setUp(): void
    {
        $this->router = (string) file_get_contents((string) (new ReflectionClass(AiModelRouterService::class))->getFileName());
        $this->targets = (string) file_get_contents((string) (new ReflectionClass(AiRoutingTargetService::class))->getFileName());
    }

    public function test_fast_path_is_strict_and_missing_exact_model_never_falls_back(): void
    {
        self::assertStringContainsString('$context->preferredModelId !== null', $this->router);
        self::assertStringContainsString('$context->requirePreferredModel', $this->router);
        self::assertStringContainsString('$context->maxAiAttempts === 1', $this->router);
        self::assertStringContainsString('($exactFastPath ? [] : $this->resolveAll', $this->router);
    }

    public function test_exact_lookup_reuses_freshness_membership_and_static_eligibility_gates(): void
    {
        $freshness = strpos($this->targets, 'ensureFreshEnough');
        $membership = strpos($this->targets, 'effectiveAreaModels', $freshness === false ? 0 : $freshness);
        self::assertIsInt($freshness);
        self::assertIsInt($membership);
        self::assertLessThan($membership, $freshness);
        self::assertStringContainsString("SeoAiModel::STATUS_ACTIVE", $this->targets);
        self::assertStringContainsString("(string) \$connection->status !== 'active'", $this->targets);
        self::assertStringContainsString('AiConnectionCredential::isUsable', $this->targets);
        self::assertStringContainsString('satisfiesAll', $this->targets);
        self::assertStringContainsString('AiProductionRouteEligibility())->filter([$candidate]', $this->targets);
        self::assertStringContainsString('$context->isFreeOnly() && ! $candidate->isFree', $this->targets);
    }

    public function test_runtime_health_capacity_and_normal_routing_remain_in_shared_router(): void
    {
        self::assertStringContainsString('$health->skipReason($userId, $candidate)', $this->router);
        self::assertStringContainsString('$this->routeCapacityPolicy()->evaluate(', $this->router);
        self::assertStringContainsString('$this->resolveAll($profile, $context)', $this->router);
    }
}
