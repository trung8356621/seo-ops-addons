<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublishingRequiresAutomationRuleContractTest extends TestCase
{
    public function test_delivery_path_checks_emit_outcome_before_mark_processing(): void
    {
        $handler = (string) file_get_contents(
            (string) (new ReflectionClass(ProcessScheduledProjectItemPublishHandler::class))->getFileName(),
        );

        self::assertStringContainsString('emitWithOutcome', $handler);
        self::assertStringContainsString('isSkippedNoRule', $handler);
        self::assertStringContainsString('dispatch-publish-request', $handler);
        self::assertStringContainsString('automation:seed-rules', $handler);

        $emitPos = strpos($handler, 'emitWithOutcome');
        $processPos = strpos($handler, 'markProcessing');
        self::assertNotFalse($emitPos);
        self::assertNotFalse($processPos);
        self::assertLessThan($processPos, $emitPos);
    }

    public function test_emitter_exposes_emit_with_outcome(): void
    {
        $emitter = (string) file_get_contents(
            (string) (new ReflectionClass(BusinessHookEmitter::class))->getFileName(),
        );

        self::assertStringContainsString('function emitWithOutcome', $emitter);
        self::assertStringContainsString('dispatchWithOutcome', $emitter);
    }
}
