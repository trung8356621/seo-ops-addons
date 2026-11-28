<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * Temporary Idea Manager browser diagnostics (?idea_debug=1).
 */
final class IdeaManagerRuntimeDiagnosticsContractTest extends TestCase
{
    public function test_debug_flag_requires_manager_and_exact_query(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName()
        );
        self::assertStringContainsString('public bool $ideaDebug = false', $src);
        self::assertStringContainsString('function resolveIdeaDebugFlag', $src);
        self::assertStringContainsString('canAccessManagerFeatures', $src);
        self::assertStringContainsString("\$raw === '1' || \$raw === 1", $src);

        $method = new ReflectionMethod(ContentProjectIdeaManager::class, 'resolveIdeaDebugFlag');
        self::assertTrue($method->isProtected());
    }

    public function test_panel_reads_production_alpine_state_and_is_gated(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        self::assertStringContainsString('data-im-debug="1"', $blade);
        self::assertStringContainsString('x-show="debugEnabled"', $blade);
        self::assertStringContainsString('debugEnabled: @js((bool) $this->ideaDebug)', $blade);
        // Same reactive sources as x-for / empty-state.
        self::assertStringContainsString('x-text="(items || []).length"', $blade);
        self::assertStringContainsString('x-text="filteredItems().length"', $blade);
        self::assertStringContainsString('probePresentIn(filteredItems())', $blade);
        self::assertStringContainsString('probePresentIn(items)', $blade);
        self::assertStringContainsString('probePresentIn(serverMeta.note_items)', $blade);
        self::assertStringContainsString('setWorkingItems', $blade);
        self::assertStringContainsString('traceWorkingSetReplace', $blade);
        self::assertStringContainsString('debugEvents', $blade);
    }

    public function test_diagnostics_are_readonly_and_avoid_sensitive_dumps(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');
        self::assertStringNotContainsString('localStorage.clear', $blade);
        self::assertStringNotContainsString('localStorage.removeItem', $blade);
        self::assertStringContainsString('never Idea text or draft JSON', $blade);
        self::assertStringNotContainsString('JSON.stringify(this.items)', $blade);
        self::assertStringNotContainsString('JSON.stringify(draft)', $blade);

        // Debug history line must stay count/owner only.
        self::assertStringContainsString(
            "ev.seq + ' ' + ev.name + ' ' + ev.before + '→' + ev.after + ' src=' + ev.source",
            $blade,
        );
        self::assertStringNotContainsString('ev.seed', $blade);
        self::assertStringNotContainsString('ev.note_items', $blade);
        self::assertStringNotContainsString('ev.payload', $blade);
    }
}
