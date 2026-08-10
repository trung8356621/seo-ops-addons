<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use PHPUnit\Framework\TestCase;

final class SeoProjectTaskMoveServiceTest extends TestCase
{
    public function test_it_exposes_delete_rollback_and_move_apis(): void
    {
        $service = new SeoProjectTaskMoveService;

        self::assertTrue(method_exists($service, 'deleteProjectRollingBackToPreviousMonth'));
        self::assertTrue(method_exists($service, 'moveTasksToProject'));
        self::assertTrue(method_exists($service, 'moveTargetOptions'));
        self::assertTrue(method_exists($service, 'findOrCreatePreviousMonthProject'));
        self::assertTrue(method_exists($service, 'assertTargetHasCapacity'));
    }
}
