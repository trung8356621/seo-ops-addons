<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Static gate: Article Editor import graph must not recreate production TDZ cycles.
 * Pure PHP — remote hosts often have no Node binary.
 */
final class ArticleEditorRuntimeImportGraphTest extends TestCase
{
    /**
     * Primary resources/js root: article editor runtime/modules/utils/components
     * live here after the frontend extraction (Task 7). Some individual modules
     * (e.g. featured sidebar) moved to peer addons and are resolved via
     * jsRoots() fallback in js().
     */
    private function jsRoot(): string
    {
        return dirname(__DIR__, 2).'/resources/js';
    }

    /**
     * @return list<string>
     */
    private function jsRoots(): array
    {
        $roots = [$this->jsRoot()];
        $addonsDir = dirname(__DIR__, 3);
        foreach (glob($addonsDir.'/*/resources/js', GLOB_ONLYDIR) ?: [] as $root) {
            if (! in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    private function js(string $relative): string
    {
        foreach ($this->jsRoots() as $root) {
            $path = $root.'/'.$relative;
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        self::fail(sprintf('Unable to locate "%s" under any addon resources/js root', $relative));
    }

    public function test_runtime_singleton_does_not_import_modules_aggregate(): void
    {
        $defaultRuntime = $this->js('editor/runtime/defaultArticleEditorRuntime.js');
        $runtimeIndex = $this->js('editor/runtime/index.js');

        self::assertStringNotContainsString("from '../modules'", $defaultRuntime);
        self::assertStringNotContainsString("from '../modules'", $runtimeIndex);
        self::assertStringContainsString('getBuiltinArticleEditorModules', $defaultRuntime);
        self::assertStringContainsString('builtinModulesRegistry', $defaultRuntime);
    }

    public function test_composition_root_registers_modules_before_editor(): void
    {
        $entry = $this->js('article-editor.jsx');
        $modulesIndex = $this->js('editor/modules/index.js');

        $modulesImportPos = strpos($entry, "import './editor/modules'");
        $editorImportPos = strpos($entry, "import SeoArticleEditor from './components/SeoArticleEditor'");
        self::assertNotFalse($modulesImportPos);
        self::assertNotFalse($editorImportPos);
        self::assertLessThan($editorImportPos, $modulesImportPos);

        self::assertStringContainsString('setBuiltinArticleEditorModules', $modulesIndex);
        self::assertStringContainsString('builtinModulesRegistry', $modulesIndex);
    }

    public function test_featured_and_module_helpers_do_not_import_runtime_singleton(): void
    {
        $featured = $this->js('editor/modules/featured/FeaturedSidebarPanel.jsx');
        $helpers = $this->js('utils/articleEditorModules.js');

        // Comments may mention the singleton name; only real import paths are forbidden.
        self::assertDoesNotMatchRegularExpression(
            "/from\\s+['\"][^'\"]*defaultArticleEditorRuntime['\"]/",
            $featured,
        );
        self::assertDoesNotMatchRegularExpression(
            "/from\\s+['\"][^'\"]*defaultArticleEditorRuntime['\"]/",
            $helpers,
        );
        self::assertStringContainsString('useEditorHostApiOptional', $featured);
    }

    public function test_link_scroll_plain_text_cycle_stays_broken(): void
    {
        $plain = $this->js('utils/articlePlainTextRange.js');
        $scroll = $this->js('utils/articleLinkScroll.js');
        $normalize = $this->js('utils/articleLinkTextNormalize.js');

        self::assertStringContainsString("from './articleLinkTextNormalize'", $plain);
        self::assertStringNotContainsString("from './articleLinkScroll'", $plain);
        self::assertStringContainsString('export function normalizeLinkText', $normalize);
        self::assertStringContainsString("from './articlePlainTextRange'", $scroll);
    }

    public function test_php_static_cycle_gate_for_critical_edges(): void
    {
        $script = ProjectRoot::addonsPath().'/content/scripts/check-editor-cycles.cjs';
        self::assertFileExists($script);

        $graph = $this->buildRelativeImportGraph();
        $cycles = $this->findCycles($graph, [
            'article-editor.jsx',
            'components/SeoArticleEditor.jsx',
            'editor/runtime/defaultArticleEditorRuntime.js',
            'editor/runtime/index.js',
            'editor/modules/index.js',
            'utils/articleLinkScroll.js',
            'utils/articlePlainTextRange.js',
            'utils/articleEditorModules.js',
            'components/BlockFormatToolbar.jsx',
        ]);

        self::assertSame([], $cycles, "Import cycles found:\n".implode("\n", $cycles));

        $defaultRuntimeImports = $graph['editor/runtime/defaultArticleEditorRuntime.js'] ?? [];
        self::assertNotContains(
            'editor/modules/index.js',
            $defaultRuntimeImports,
            'defaultArticleEditorRuntime must not import modules aggregate',
        );

        $modulesImports = $graph['editor/modules/index.js'] ?? [];
        self::assertContains(
            'editor/runtime/builtinModulesRegistry.js',
            $modulesImports,
            'modules index must register builtinModulesRegistry',
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function buildRelativeImportGraph(): array
    {
        $root = $this->jsRoot();
        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
            '/\\.(js|jsx)$/',
        );

        $graph = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $from = str_replace('\\', '/', substr($abs, strlen($root) + 1));
            $text = (string) file_get_contents($abs);
            $targets = [];
            if (preg_match_all(
                "/(?:import\\s+(?:[^'\"\\n]+from\\s+)?|export\\s+(?:[^'\"\\n]+from\\s+))['\"](\\.[^'\"]+)['\"]/",
                $text,
                $matches,
            ) > 0) {
                foreach ($matches[1] as $spec) {
                    $resolved = $this->resolveRelativeImport($from, $spec);
                    if ($resolved !== null) {
                        $targets[$resolved] = true;
                    }
                }
            }
            $graph[$from] = array_keys($targets);
        }

        return $graph;
    }

    private function resolveRelativeImport(string $from, string $spec): ?string
    {
        $root = $this->jsRoot();
        $fromDir = str_replace('\\', '/', dirname($from));
        if ($fromDir === '.' || $fromDir === '') {
            $joined = $spec;
        } else {
            $joined = $fromDir.'/'.$spec;
        }
        $normalized = str_replace('\\', '/', $joined);
        $normalized = preg_replace('#^\./#', '', $normalized) ?? $normalized;
        while (str_contains($normalized, '/./')) {
            $normalized = str_replace('/./', '/', $normalized);
        }
        // Collapse "dir/../"
        while (preg_match('#(^|/)(?!\.\./)[^/]+/\.\.(/|$)#', $normalized) === 1) {
            $normalized = (string) preg_replace('#(^|/)(?!\.\./)[^/]+/\.\.(/|$)#', '$1', $normalized, 1);
            $normalized = trim($normalized, '/');
        }
        $normalized = ltrim($normalized, '/');

        $candidates = [
            $normalized,
            $normalized.'.js',
            $normalized.'.jsx',
            $normalized.'/index.js',
            $normalized.'/index.jsx',
        ];
        foreach ($candidates as $candidate) {
            $abs = $root.'/'.$candidate;
            if (is_file($abs)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $graph
     * @param  list<string>  $seeds
     * @return list<string>
     */
    private function findCycles(array $graph, array $seeds): array
    {
        $cycles = [];
        $seenKeys = [];

        foreach ($seeds as $seed) {
            if (! isset($graph[$seed])) {
                continue;
            }
            $stack = [];
            $inStack = [];
            $this->dfsCycles($seed, $graph, $stack, $inStack, $cycles, $seenKeys);
        }

        return array_values($cycles);
    }

    /**
     * @param  array<string, list<string>>  $graph
     * @param  list<string>  $stack
     * @param  array<string, bool>  $inStack
     * @param  array<string, string>  $cycles
     * @param  array<string, bool>  $seenKeys
     */
    private function dfsCycles(
        string $node,
        array $graph,
        array &$stack,
        array &$inStack,
        array &$cycles,
        array &$seenKeys,
    ): void {
        if (isset($inStack[$node])) {
            $idx = array_search($node, $stack, true);
            if ($idx === false) {
                return;
            }
            $cycleNodes = array_slice($stack, (int) $idx);
            $cycleNodes[] = $node;
            $key = implode(' -> ', $cycleNodes);
            if (! isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $cycles[$key] = $key;
            }

            return;
        }

        $inStack[$node] = true;
        $stack[] = $node;
        foreach ($graph[$node] ?? [] as $next) {
            $this->dfsCycles($next, $graph, $stack, $inStack, $cycles, $seenKeys);
        }
        array_pop($stack);
        unset($inStack[$node]);
    }
}
