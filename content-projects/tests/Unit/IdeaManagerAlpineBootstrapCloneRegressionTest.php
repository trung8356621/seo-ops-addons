<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

/**
 * Regression test for Alpine reactive Proxy structuredClone DataCloneError during
 * Idea Manager bootstrap, softReloadGlobal, and partitionByOwner.
 */
final class IdeaManagerAlpineBootstrapCloneRegressionTest extends TestCase
{
    public function test_blade_unwraps_reactive_proxies_before_cloning_and_clears_hydration_error(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        self::assertStringContainsString('unwrapReactive(value)', $blade);
        self::assertStringContainsString('toPlainCloneable(value', $blade);
        self::assertStringContainsString('clonePlainItem(item)', $blade);
        self::assertStringContainsString('clonePlainItems(list)', $blade);

        self::assertStringNotContainsString('structuredClone(this.serverMeta.note_items)', $blade);
        self::assertStringNotContainsString('structuredClone(it)', $blade);
        self::assertStringContainsString("this.debugHydrationError = ''", $blade);
    }

    public function test_alpine_bootstrap_runtime_hydrates_13_ideas_under_reactive_proxies(): void
    {
        $script = dirname(__DIR__).'/Fixtures/idea_manager_alpine_bootstrap_runtime.mjs';
        self::assertFileExists($script);

        $bladePath = dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/pages/content-project-idea-manager.blade.php';
        self::assertFileExists($bladePath);

        $nodeBin = $this->findNodeBinary();
        if ($nodeBin === null) {
            self::markTestSkipped('Node.js binary not available in PATH for JS runtime harness.');
        }

        $cmd = escapeshellarg($nodeBin).' '.escapeshellarg($script).' '.escapeshellarg($bladePath);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        self::assertSame(0, $exitCode, "Node runtime harness failed:\nSTDOUT: {$stdout}\nSTDERR: {$stderr}");
        self::assertStringContainsString('ALPINE_BOOTSTRAP_RUNTIME_OK', $stdout);
    }

    private function findNodeBinary(): ?string
    {
        $candidates = DIRECTORY_SEPARATOR === '\\' ? ['where node'] : ['which node'];
        foreach ($candidates as $cmd) {
            $out = @shell_exec($cmd);
            if (is_string($out) && trim($out) !== '') {
                $first = trim((string) strtok(trim($out), "\r\n"));
                if ($first !== '') {
                    return $first;
                }
            }
        }

        return null;
    }
}
