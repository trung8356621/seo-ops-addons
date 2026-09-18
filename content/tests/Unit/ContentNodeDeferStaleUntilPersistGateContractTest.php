<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * ContentNode finalize must not early-return ignored_stale before persist gate —
 * workflow Save may already have written body; early return causes false human-edit skip.
 */
final class ContentNodeDeferStaleUntilPersistGateContractTest extends TestCase
{
    public function test_finalize_defers_stale_guard_for_content_node(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php';
        $src = (string) file_get_contents($path);
        self::assertFileExists($path);
        self::assertStringContainsString('deferStaleToPersistGate', $src);
        self::assertStringContainsString('ArticleWritingExecutionMode::ContentNode', $src);

        $finalizePos = strpos($src, 'function finalizeWorkflowSteps');
        self::assertNotFalse($finalizePos);
        $ensureGeneratedContentPersistedMethod = strpos($src, 'function ensureGeneratedContentPersisted');
        self::assertNotFalse($ensureGeneratedContentPersistedMethod);

        $deferPos = strpos($src, 'deferStaleToPersistGate', $finalizePos);
        self::assertNotFalse($deferPos);
        self::assertLessThan(
            $ensureGeneratedContentPersistedMethod,
            $deferPos,
            'defer flag lives inside finalizeWorkflowSteps before ensureGeneratedContentPersisted method',
        );

        $callPos = strpos($src, '$this->ensureGeneratedContentPersisted', $finalizePos);
        self::assertNotFalse($callPos);
        self::assertLessThan($callPos, $deferPos);
    }
}
