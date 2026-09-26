<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\Support\ProjectRoot;

/**
 * Guardrail: production peers must not import Agent Workspace implementation.
 *
 * Transitional note: Omnichannel\Addons\Agent\Automation\* and
 * Omnichannel\Addons\Agent\Extension\* may still appear outside agent/
 * until those packages are extracted. This test locks Agent Workspace
 * (Services\AgentWorkspace, Agent Filament chat) out of other addons.
 */
final class AgentWorkspaceIsolationContractTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN_NEEDLES = [
        'Omnichannel\\Addons\\Agent\\Services\\AgentWorkspace\\',
        'Omnichannel\\Addons\\Agent\\Filament\\Pages\\AgentWorkspace',
        'Omnichannel\\Addons\\Agent\\Jobs\\',
    ];

    /** @var list<string> */
    private const SCOPES = [
        'content/src',
        'content-projects/src',
        'seo/src',
        'search-foundation/src',
        'search-intelligence/src',
        'ai-prompt/src',
        'media/src',
        'publishing/src',
        'wordpress/src',
        'commerce/src',
        'site-sync/src',
        'social/src',
        'seeding/src',
        'seo-content-ai-compat',
    ];

    public function test_peer_runtime_does_not_import_agent_workspace(): void
    {
        $root = ProjectRoot::addonsPath();
        $hits = [];

        foreach (self::SCOPES as $relative) {
            $base = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_dir($base) && ! is_file($base)) {
                continue;
            }

            foreach ($this->phpFiles($base) as $file) {
                // Compat may still ship historical Blade under agent-workspace name;
                // only flag PHP + Blade that import Agent Workspace types.
                $contents = (string) file_get_contents($file);
                foreach (self::FORBIDDEN_NEEDLES as $needle) {
                    if (! str_contains($contents, $needle)) {
                        continue;
                    }
                    // Allow comments that document isolation.
                    if ($this->isDocumentationOnlyHit($contents, $needle)) {
                        continue;
                    }
                    $hits[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file).' → '.$needle;
                }
            }
        }

        self::assertSame([], $hits, "Agent Workspace imports leaked outside agent/:\n".implode("\n", $hits));
    }

    public function test_seo_panel_does_not_discover_agent_filament_peer(): void
    {
        $path = ProjectRoot::addonsPath()
            .DIRECTORY_SEPARATOR.'seo-content-ai-compat'
            .DIRECTORY_SEPARATOR.'Providers'
            .DIRECTORY_SEPARATOR.'SeoPanelProvider.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString("'agent' => 'Agent'", $source);
    }

    public function test_agent_addon_marked_legacy(): void
    {
        $path = ProjectRoot::addonsPath().DIRECTORY_SEPARATOR.'agent'.DIRECTORY_SEPARATOR.'addon.json';
        self::assertFileExists($path);
        $meta = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($meta);
        self::assertTrue((bool) ($meta['legacy'] ?? false));
        self::assertFileExists(ProjectRoot::addonsPath().DIRECTORY_SEPARATOR.'agent'.DIRECTORY_SEPARATOR.'README.md');
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $base): array
    {
        if (is_file($base)) {
            return str_ends_with($base, '.php') || str_ends_with($base, '.blade.php') ? [$base] : [];
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        $files = new RegexIterator($iterator, '/\.(php|blade\.php)$/');
        $out = [];
        foreach ($files as $file) {
            $path = (string) $file->getPathname();
            if (str_contains($path, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            // Historical Agent Workspace Blade retained as reference UI fragment.
            if (str_contains($path, 'agent-workspace')) {
                continue;
            }
            $out[] = $path;
        }

        return $out;
    }

    private function isDocumentationOnlyHit(string $contents, string $needle): bool
    {
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            if (! str_contains($line, $needle)) {
                continue;
            }
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            return false;
        }

        return true;
    }
}
