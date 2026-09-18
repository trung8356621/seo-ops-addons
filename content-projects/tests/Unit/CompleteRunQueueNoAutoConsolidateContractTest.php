<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * completeRunQueue must not auto-consolidate — that overwrote live Writing failures
 * with historical success snapshots (false-success).
 */
final class CompleteRunQueueNoAutoConsolidateContractTest extends TestCase
{
    public function test_complete_run_queue_does_not_call_maybe_consolidate(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php';
        $src = (string) file_get_contents($path);
        self::assertFileExists($path);

        $pos = strpos($src, 'function completeRunQueue');
        self::assertNotFalse($pos);
        $chunk = substr($src, $pos, 1800);

        self::assertDoesNotMatchRegularExpression(
            '/maybeConsolidate\s*\(/',
            $chunk,
            'completeRunQueue must not invoke maybeConsolidate()',
        );
        self::assertStringContainsString('Do NOT call SeoProjectRunConsolidationService::maybeConsolidate', $chunk);
        self::assertStringContainsString('notifyRunCompleted', $chunk);
    }
}
