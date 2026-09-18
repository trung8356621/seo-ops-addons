<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class RewriteHumanEditConflictAppliedPersistContractTest extends TestCase
{
    public function test_applied_persist_skips_human_edit_conflict_check(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php';
        $src = (string) file_get_contents($path);
        self::assertFileExists($path);

        self::assertMatchesRegularExpression(
            '/\$persistStatus\s*===\s*[\'"]applied[\'"]\s*\?\s*null\s*:\s*\$this->rewriteHumanEditConflictMessage/s',
            $src,
        );
        self::assertStringContainsString(
            'AI Writing persist bumps articles.updated_at',
            $src,
        );
    }
}
