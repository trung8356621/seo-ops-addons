<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource\Pages\EditArticle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class ArticleEditorIdleNetworkAndManualSeoContractTest extends TestCase
{
    public function test_schedule_autosave_does_not_run_seo_analysis(): void
    {
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');
        self::assertStringContainsString('const scheduleAutosave = useCallback', $queue);
        self::assertStringNotContainsString('runLocalSeoAnalysis', $queue);
        self::assertStringNotContainsString('previewSeoScoreViaApi', $queue);
        self::assertStringContainsString('KHÔNG còn gọi SEO analyze', $queue);
    }

    public function test_live_scheduler_debounces_local_analysis_only(): void
    {
        $hook = $this->js('hooks/useArticleEditorSeoAnalysis.js');
        self::assertStringContainsString('const markSeoStale = useCallback', $hook);
        self::assertStringContainsString('setSeoStaleRevision((current) => current + 1)', $hook);
        self::assertStringContainsString('runLocalSeoAnalysis()', $hook);
        self::assertStringContainsString('}, 450)', $hook);
        self::assertStringNotContainsString('runPhpSeoPreview', $hook);
        self::assertStringNotContainsString('previewSeoScoreViaApi', $hook);
    }

    public function test_content_and_meta_changes_mark_seo_stale_only(): void
    {
        $bridge = $this->js('hooks/useArticleEditorExternalEventsBridge.js');
        self::assertStringContainsString('markSeoStale();', $bridge);
        self::assertStringNotContainsString('scheduleIdleSeoAnalysis', $bridge);
        self::assertStringNotContainsString('requestAnalyze();', $bridge);

        $tx = $this->js('utils/editorCommands/runEditorTransaction.js');
        self::assertStringContainsString('context.markSeoStale()', $tx);
        self::assertStringNotContainsString('context.requestAnalyze()', $tx);

        $outline = $this->js('hooks/useArticleEditorOutline.js');
        self::assertStringContainsString('markSeoStale()', $outline);
        self::assertStringNotContainsString('scheduleIdleSeoAnalysis', $outline);
    }

    public function test_explicit_analyze_is_local_only(): void
    {
        $hook = $this->js('hooks/useArticleEditorSeoAnalysis.js');
        $request = $this->methodLike($hook, 'const requestAnalyze = useCallback');
        self::assertStringContainsString('runLocalSeoAnalysis()', $request);
        self::assertStringNotContainsString('runPhpSeoPreview', $request);

        $panel = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/components/SeoScorePanel.jsx',
        );
        self::assertStringContainsString('editor_seo_update_score', $panel);
        self::assertStringContainsString('onClick={onAnalyzeClick}', $panel);
    }

    public function test_seo_lazy_load_fetches_settings_without_server_summary(): void
    {
        $lazy = $this->js('utils/articleEditorSeoLazy.js');
        self::assertStringContainsString('loadArticleEditorSeoSettings', $lazy);
        self::assertStringNotContainsString('seoSummaryUrl', $lazy);
        self::assertStringNotContainsString('previewSeoScoreViaApi', $lazy);
        self::assertStringNotContainsString('runLocalSeoAnalysis', $lazy);

        $state = $this->js('hooks/useArticleEditorSeoAndLinksState.js');
        self::assertStringContainsString('seo-editor-seo-settings-loaded', $state);
        self::assertStringNotContainsString('seo-editor-seo-summary-loaded', $state);
        self::assertStringNotContainsString('seo-summary', $state);
        self::assertStringNotContainsString('previewSeoScoreViaApi', $state);
        self::assertStringNotContainsString('requestAnalyze', $state);

        $shell = $this->js('article-editor.jsx');
        self::assertStringNotContainsString('/editor/seo-summary', $shell);
        self::assertStringNotContainsString('seo-editor-seo-summary-loaded', $shell);

        $network = $this->js('utils/articleEditorNetwork.js');
        self::assertStringNotContainsString('/editor/seo-summary', $network);
    }

    public function test_unchanged_article_does_not_autosave(): void
    {
        $queue = $this->js('hooks/useArticleEditorSaveQueue.js');
        self::assertStringContainsString('lastAutosaveHashRef', $queue);

        $network = $this->js('hooks/useArticleEditorSessionNetwork.js');
        self::assertStringContainsString('currentBodyHash !== ackBodyHash', $network);
    }

    public function test_editor_lease_renew_is_activity_gated_and_does_not_poll_or_call_livewire(): void
    {
        $client = $this->js('utils/editorSessionClient.js');
        self::assertStringContainsString('edit-lease/${this.sessionId}', $client);
        self::assertStringContainsString('_leaseRenewInFlight', $client);
        self::assertStringContainsString('recentlyActive', $client);
        self::assertStringContainsString('visibilityState === \'visible\'', $client);
        self::assertStringNotContainsString('setInterval', $client);
        self::assertStringNotContainsString('Livewire.find', $client);
        self::assertStringNotContainsString('component.set', $client);
        self::assertStringNotContainsString("component?.set", $client);

        $shell = $this->js('article-editor.jsx');
        self::assertStringNotContainsString("component?.set?.('editorSessionId'", $shell);
        self::assertStringNotContainsString("component?.set?.('expectedDocumentVersion'", $shell);

        $lw = $this->js('utils/articleEditorLivewire.js');
        self::assertStringContainsString('applyEditorSessionTokensLocally', $lw);
        self::assertStringContainsString("setter('editorSessionId', sessionId, false)", $lw);
    }

    public function test_loaded_editor_has_no_readiness_wire_poll(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/article-resource/pages/edit-article.blade.php'),
        );
        self::assertStringContainsString('@if ($this->editorPreparing)', $blade);
        self::assertStringContainsString('wire:poll.3s="pollEditorReadiness"', $blade);
        $pollPos = strpos($blade, 'wire:poll.3s="pollEditorReadiness"');
        $ifPos = strpos($blade, '@if ($this->editorPreparing)');
        $endifPos = strpos($blade, '@endif', $ifPos !== false ? $ifPos : 0);
        self::assertNotFalse($pollPos);
        self::assertNotFalse($ifPos);
        self::assertNotFalse($endifPos);
        self::assertTrue($pollPos > $ifPos && $pollPos < $endifPos);

        $edit = (string) file_get_contents((string) (new ReflectionClass(EditArticle::class))->getFileName());
        self::assertSame(1, substr_count($edit, 'function pollEditorReadiness'));
    }

    private function js(string $relative): string
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function methodLike(string $source, string $startNeedle): string
    {
        $start = strpos($source, $startNeedle);
        self::assertNotFalse($start);
        $chunk = substr($source, $start, 900);

        return $chunk;
    }
}
