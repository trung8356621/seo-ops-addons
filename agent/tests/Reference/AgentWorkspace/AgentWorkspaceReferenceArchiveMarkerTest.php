<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Reference\AgentWorkspace;

use PHPUnit\Framework\TestCase;

/**
 * Marker: Agent Workspace product tests under this directory are REFERENCE-ONLY.
 * They are excluded from the default AgentAddon PHPUnit suite.
 */
final class AgentWorkspaceReferenceArchiveMarkerTest extends TestCase
{
    public function test_archive_directory_exists(): void
    {
        self::assertDirectoryExists(__DIR__);
    }
}
