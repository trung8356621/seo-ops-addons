<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Hotfix: active runtime polling must remorph rows via lazyRefreshOps when state changes.
 */
final class ContentProjectOpsRuntimePollRefreshTest extends TestCase
{
    public function test_fetch_ops_summary_only_always_skip_renders(): void
    {
        $src = $this->viewSrc();
        $pos = strpos($src, 'function fetchOpsSummaryOnly');
        self::assertNotFalse($pos);
        $next = strpos($src, "\n    private function ", $pos + 1);
        if ($next === false) {
            $next = strpos($src, "\n    public function ", $pos + 1);
        }
        $chunk = $next !== false ? substr($src, $pos, $next - $pos) : substr($src, $pos, 800);
        self::assertStringContainsString('$this->skipRender();', $chunk);
        self::assertStringNotContainsString('invalidateOpsCache', $chunk);
    }

    public function test_lazy_refresh_ops_skip_render_only_when_unchanged(): void
    {
        $src = $this->viewSrc();
        $pos = strpos($src, 'function lazyRefreshOps');
        self::assertNotFalse($pos);
        $next = strpos($src, "\n    public function manualRefreshOps", $pos + 1);
        $chunk = $next !== false ? substr($src, $pos, $next - $pos) : substr($src, $pos, 1200);

        self::assertStringContainsString('summaryFingerprint', $chunk);
        self::assertStringContainsString("return ['changed' => false, 'summary' => \$summary];", $chunk);
        self::assertStringContainsString("return ['changed' => true, 'summary' => \$summary];", $chunk);
        self::assertStringContainsString('invalidateOpsCache()', $chunk);

        // skipRender only on the unchanged branch — not before the fingerprint compare.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$changed\s*\)\s*\{\s*\$this->skipRender\(\);/s',
            $chunk,
        );
        self::assertDoesNotMatchRegularExpression(
            '/function lazyRefreshOps\(\): array\s*\{[^}]*\$this->skipRender\(\);[^}]*\$changed\s*=/s',
            $chunk,
        );
    }

    public function test_runtime_poll_uses_lazy_refresh_not_summary_only(): void
    {
        $blade = $this->opsBlade();
        self::assertStringContainsString('await $wire.lazyRefreshOps()', $blade);
        self::assertStringContainsString('result?.changed', $blade);

        // Non-force path must invoke lazyRefreshOps (not the always-skipRender summary-only API).
        $pos = strpos($blade, 'async doLazyRefresh(force = false)');
        self::assertNotFalse($pos);
        $chunk = substr($blade, $pos, 1800);
        self::assertStringContainsString('$wire.lazyRefreshOps()', $chunk);
        self::assertStringContainsString('manualRefreshOps()', $chunk);
        self::assertDoesNotMatchRegularExpression(
            '/else\s*\{[^}]*\$wire\.fetchOpsSummaryOnly/s',
            $chunk,
        );
    }

    public function test_livewire_morph_syncs_counters_without_relying_on_generation_event(): void
    {
        $blade = $this->opsBlade();
        self::assertStringContainsString('data-ops-summary=', $blade);
        self::assertStringContainsString("attributeFilter: ['data-ops-summary']", $blade);
        self::assertStringContainsString('this.acceptCanonicalSummary(summary', $blade);
        self::assertStringContainsString('this.summaryObserver?.disconnect()', $blade);
    }

    public function test_terminal_poll_applies_refresh_before_stopping(): void
    {
        $blade = $this->opsBlade();
        $pos = strpos($blade, 'async runRuntimePoll(attempt = 0)');
        self::assertNotFalse($pos);
        $chunk = substr($blade, $pos, 900);

        $lazyPos = strpos($chunk, 'doLazyRefresh(false)');
        $stopCheckPos = strpos($chunk, 'shouldPollRuntime()');
        self::assertNotFalse($lazyPos);
        self::assertNotFalse($stopCheckPos);
        self::assertLessThan(
            $stopCheckPos,
            $lazyPos,
            'Final terminal summary/render must apply before poll stop check',
        );
        self::assertStringContainsString('hasOptimisticProcessing()', $chunk);
    }

    public function test_optimistic_processing_overlay_clears_after_remorph(): void
    {
        $blade = $this->opsBlade();
        $pos = strpos($blade, 'async doLazyRefresh(force = false)');
        self::assertNotFalse($pos);
        $chunk = substr($blade, $pos, 2200);
        self::assertStringContainsString('if (changed || force)', $chunk);
        self::assertStringContainsString('this.processingRows = {}', $chunk);

        self::assertStringContainsString('startRuntimePoll()', $blade);
        $beginPos = strpos($blade, 'beginRowProcessing(tid, kind)');
        self::assertNotFalse($beginPos);
        $beginChunk = substr($blade, $beginPos, 600);
        self::assertStringContainsString("=== 'generation'", $beginChunk);
        self::assertStringContainsString('this.startRuntimePoll()', $beginChunk);
    }

    public function test_activity_cells_do_not_freeze_server_runtime_in_alpine_x_show(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-items-list.blade.php');

        self::assertDoesNotMatchRegularExpression('/x-show="[^"]*\{\{[^}]*is_activity_processing/', $blade);
        self::assertDoesNotMatchRegularExpression('/x-show="[^"]*\{\{[^}]*serverActivityProcessing/', $blade);
        self::assertStringContainsString('@if ($serverActivityProcessing && ! $rowPending)', $blade);
        self::assertStringContainsString("x-show=\"typeof isRowProcessing !== 'function' || ! isRowProcessing({{ \$tid }})\"", $blade);
    }

    public function test_generation_force_refresh_survives_an_in_flight_poll(): void
    {
        $blade = $this->opsBlade();

        self::assertStringContainsString('if (force) this.forceRefreshQueued = true', $blade);
        self::assertStringContainsString('queueMicrotask(() => this.doLazyRefresh(true)', $blade);
        self::assertStringContainsString('if (! refreshed && attempt < 200)', $blade);
    }

    public function test_runtime_poll_interval_is_three_to_four_seconds(): void
    {
        $blade = $this->opsBlade();
        self::assertMatchesRegularExpression(
            '/startRuntimePoll\(\)\s*\{[\s\S]*?setTimeout\(\(\)\s*=>\s*\{[\s\S]*?\},\s*3000\)/',
            $blade,
        );
        self::assertMatchesRegularExpression(
            '/runRuntimePoll\(attempt\s*\+\s*1\),\s*4000\)/',
            $blade,
        );
        self::assertStringContainsString('onGenerationStarted()', $blade);
        self::assertStringContainsString('runtime_revision', (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        ));
        self::assertStringNotContainsString('300000', $blade);
        self::assertStringNotContainsString('5 * 60', $blade);
        self::assertStringNotContainsString('runGenerationTablePoll', $blade);
        self::assertStringNotContainsString('startGenerationTablePoll', $blade);
        self::assertStringNotContainsString('generationPollIds', $blade);
    }

    public function test_generate_action_full_page_reloads_after_run_is_accepted(): void
    {
        $src = $this->viewSrc();
        $pos = strpos($src, 'function dispatchGenerate');
        self::assertNotFalse($pos);
        $chunk = substr($src, $pos, 4500);

        $startPos = strpos($chunk, 'SeoProjectResource::startGeneratePendingItems');
        $redirectPos = strpos($chunk, "redirect(SeoProjectResource::getUrl('view'");

        self::assertNotFalse($startPos);
        self::assertNotFalse($redirectPos);
        self::assertGreaterThan($startPos, $redirectPos);
    }

    public function test_sequential_batch_summary_fingerprint_changes_without_waiting_for_run_end(): void
    {
        $fp = static function (array $stats): string {
            $normalized = ContentProjectItemOperationsReadModel::normalizeSummaryStats($stats);

            return hash('xxh128', (string) json_encode($normalized, JSON_THROW_ON_ERROR));
        };

        // A running, B+C pending
        $before = $fp([
            'pending' => 2,
            'needs_review' => 0,
            'running' => 1,
            'runtime_active' => 1,
            'runtime_waiting' => 0,
            'runtime_stuck' => 0,
            'should_poll_runtime' => 1,
        ]);

        // A completed (needs_review), B running, C pending — same aggregate "1 running"
        $after = $fp([
            'pending' => 1,
            'needs_review' => 1,
            'running' => 1,
            'runtime_active' => 1,
            'runtime_waiting' => 0,
            'runtime_stuck' => 0,
            'should_poll_runtime' => 1,
        ]);

        self::assertNotSame(
            $before,
            $after,
            'A completed → B running must change lazy fingerprint so rows remorph mid-batch',
        );

        // Terminal: last item done → stop polling after that render
        $terminal = $fp([
            'pending' => 0,
            'needs_review' => 2,
            'running' => 0,
            'runtime_active' => 0,
            'runtime_waiting' => 0,
            'runtime_stuck' => 0,
            'should_poll_runtime' => 0,
        ]);
        self::assertNotSame($after, $terminal);
    }

    public function test_runtime_revision_changes_when_step_progresses_without_counter_change(): void
    {
        $fp = static function (array $stats): string {
            $normalized = ContentProjectItemOperationsReadModel::normalizeSummaryStats($stats);

            return hash('xxh128', (string) json_encode($normalized, JSON_THROW_ON_ERROR));
        };

        $base = [
            'pending' => 1,
            'needs_review' => 0,
            'running' => 1,
            'runtime_active' => 1,
            'runtime_waiting' => 0,
            'runtime_stuck' => 0,
            'should_poll_runtime' => 1,
        ];

        $outline = $fp($base + ['runtime_revision' => 't1:run:ri:processing:outline:1:actively_processing']);
        $content = $fp($base + ['runtime_revision' => 't1:run:ri:processing:article.content.generate:1:actively_processing']);

        self::assertNotSame(
            $outline,
            $content,
            'Outline → content step must remorph even when summary counters are unchanged',
        );
    }

    public function test_x_data_attribute_has_no_raw_double_quotes(): void
    {
        $blade = $this->opsBlade();
        $start = strpos($blade, 'x-data="{');
        self::assertNotFalse($start);
        $end = strpos($blade, "\n        }\"", $start);
        self::assertNotFalse($end);
        $body = substr($blade, $start + strlen('x-data="'), $end - ($start + strlen('x-data="')));
        // Raw " inside x-data="..." closes the HTML attribute and dumps JS onto the page.
        self::assertStringNotContainsString('"', $body);
    }

    public function test_visibility_and_pageshow_use_non_force_lazy_path(): void
    {
        $blade = $this->opsBlade();
        self::assertStringContainsString("visibilitychange", $blade);
        self::assertStringContainsString("maybeLazyRefresh(false)", $blade);
        self::assertStringContainsString("pageshow", $blade);
        self::assertStringNotContainsString('window.location.reload()', $blade);
    }

    private function viewSrc(): string
    {
        return (string) file_get_contents((string) (new ReflectionClass(ViewSeoProject::class))->getFileName());
    }

    private function opsBlade(): string
    {
        return LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php',
        );
    }
}
