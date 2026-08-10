<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemKind;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectRunItemClassifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * HOTFIX: terminal run + pending helper/step rows — classifier + source contracts.
 * Full DB apply chạy trên hosting với SEO connection.
 */
final class ContentProjectRunTerminalHelperHotfixTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_classifier_splits_article_step_helper_by_action_column(): void
    {
        self::assertSame(
            SeoProjectRunItemKind::Article,
            SeoProjectRunItemClassifier::classify(SeoProjectRunAction::ArticleCreate->value),
        );
        self::assertSame(
            SeoProjectRunItemKind::Article,
            SeoProjectRunItemClassifier::classify(SeoProjectRunAction::ArticleRewrite->value),
        );
        self::assertSame(
            SeoProjectRunItemKind::WorkflowStep,
            SeoProjectRunItemClassifier::classify('step:outline.generate'),
        );
        self::assertSame(
            SeoProjectRunItemKind::Helper,
            SeoProjectRunItemClassifier::classify('manual:orphan'),
        );
        self::assertSame(
            SeoProjectRunItemKind::Helper,
            SeoProjectRunItemClassifier::classify(''),
        );
        self::assertTrue(SeoProjectRunItemClassifier::isHelperOrControl('step:x'));
        self::assertFalse(SeoProjectRunItemClassifier::isHelperOrControl('article.create'));
    }

    public function test_model_scopes_and_engine_use_article_execution_not_raw_pending(): void
    {
        $model = $this->source('Models/SeoProjectRunItem.php');
        self::assertStringContainsString('function scopeArticleExecution', $model);
        self::assertStringContainsString('function scopeHelperOrControl', $model);
        self::assertStringContainsString('function scopeWorkflowStep', $model);

        $engine = $this->source('Services/RunEngine/ContentProjectRunEngine.php');
        self::assertStringContainsString('articleExecution()', $engine);
        self::assertStringContainsString('helperOrControl()', $engine);
        self::assertStringContainsString('normalizeTerminalHelperRows', $engine);
        self::assertStringContainsString('run_terminal_with_pending_article_items', $engine);
        self::assertStringContainsString('run_terminal_with_pending_helper_items', $engine);
        self::assertStringContainsString('normalize_terminal_helper_rows', $engine);
        self::assertStringContainsString('Skipped->value', $engine);
        self::assertStringNotContainsString("where('action', 'not like', 'step:%')", $engine);
    }

    public function test_finalize_calls_normalize_helpers_and_never_bulk_success_articles(): void
    {
        $engine = $this->source('Services/RunEngine/ContentProjectRunEngine.php');
        $pos = strpos($engine, 'function finalizeIfDone');
        self::assertNotFalse($pos);
        $next = strpos($engine, "\n    public function ", $pos + 1);
        $chunk = $next !== false ? substr($engine, $pos, $next - $pos) : substr($engine, $pos, 12000);

        self::assertStringContainsString('normalizeTerminalHelperRows', $chunk);
        self::assertStringContainsString('already_terminal', $chunk);

        $normPos = strpos($engine, 'function normalizeTerminalHelperRows');
        self::assertNotFalse($normPos);
        $normNext = strpos($engine, "\n    public function ", $normPos + 1);
        if ($normNext === false) {
            $normNext = strpos($engine, "\n    private function ", $normPos + 1);
        }
        $normChunk = $normNext !== false
            ? substr($engine, $normPos, $normNext - $normPos)
            : substr($engine, $normPos, 8000);

        self::assertStringContainsString('helperOrControl()', $normChunk);
        self::assertStringContainsString('SeoProjectRunItemStatus::Skipped->value', $normChunk);
        self::assertStringNotContainsString('Success->value', $normChunk);
        self::assertStringContainsString('article_processing_present', $normChunk);
        self::assertStringContainsString('run_not_terminal', $normChunk);
    }

    public function test_recovery_plan_classifies_pending_article_vs_helper(): void
    {
        $engine = $this->source('Services/RunEngine/ContentProjectRunEngine.php');
        $pos = strpos($engine, 'function recoveryPlan');
        self::assertNotFalse($pos);
        $next = strpos($engine, "\n    public function ", $pos + 1);
        $chunk = $next !== false ? substr($engine, $pos, $next - $pos) : substr($engine, $pos, 8000);

        self::assertStringContainsString("'pending_article_items'", $chunk);
        self::assertStringContainsString("'pending_helper_items'", $chunk);
        self::assertStringContainsString('eligible_for_normalize_terminal_helpers', $chunk);
        self::assertStringContainsString('normalize_terminal_helper_rows', $chunk);
    }

    public function test_recover_command_supports_normalize_action(): void
    {
        $cmd = $this->source('Console/ContentProjectRunRecoverCommand.php');
        self::assertStringContainsString('normalize-terminal-helpers', $cmd);
        self::assertStringContainsString('normalizeTerminalHelperRows', $cmd);
        self::assertStringContainsString('eligible_for_normalize_terminal_helpers', $cmd);
    }

    public function test_ui_terminal_wins_over_busy_step_and_stale_running(): void
    {
        $steps = $this->source('Services/SeoProjectWorkflowStepRetryService.php');
        self::assertStringContainsString('$runTerminal', $steps);
        // Terminal run wins: busy only when run is non-terminal AND an active step map hits.
        self::assertStringContainsString('$busy = ! $runTerminal && (', $steps);
        self::assertStringContainsString('isset($activeByNode[$nodeId])', $steps);
        self::assertStringContainsString('isset($activeByAction[$action])', $steps);
        self::assertStringContainsString("'can_retry' => ! \$busy && ! \$taskHasAnyActive && ! \$runTerminal", $steps);

        $blade = $this->source('resources/views/filament/resources/seo-project-resource/pages/view-project-run.blade.php');
        self::assertStringContainsString('$runIsTerminal', $blade);
        self::assertStringContainsString('engineUiRunning', $blade);
        self::assertStringContainsString("['completed','cancelled','failed']", $blade);

        $view = $this->source('Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php');
        self::assertStringContainsString('getProjectWorkspaceUrl', $view);
        self::assertStringNotContainsString('engineUiRunning', $view);

        $js = $this->source('resources/js/project-run-queue.js');
        self::assertStringContainsString('engineUiRunning', $js);
        self::assertStringContainsString("['completed', 'cancelled', 'failed']", $js);
    }

    public function test_counters_reader_uses_article_scope_only(): void
    {
        $reader = $this->source('Services/SeoProjectRunItemsReader.php');
        self::assertStringContainsString('articleExecution()', $reader);
        self::assertStringNotContainsString("where('action', 'not like', 'step:%')", $reader);

        $service = $this->source('Services/SeoProjectRunItemService.php');
        self::assertStringContainsString('articleExecution()', $service);
    }

    public function test_terminal_neutral_status_vocabulary_is_skipped(): void
    {
        self::assertSame('skipped', SeoProjectRunItemStatus::Skipped->value);
        self::assertContains(
            SeoProjectRunItemStatus::Skipped->value,
            SeoProjectRunItemStatus::values(),
        );
    }

    public function test_php_engine_running_status_still_drives_ui_flag(): void
    {
        $view = $this->source('Filament/Resources/SeoProjectResource/Pages/ViewSeoProjectRun.php');
        self::assertStringContainsString('getProjectWorkspaceUrl', $view);
        self::assertStringNotContainsString('STATUS_RUNNING', $view);
        $js = $this->source('resources/js/project-run-queue.js');
        self::assertStringContainsString('engineUiRunning', $js);
    }

    private function source(string $relativeFromAddonRoot): string
    {
        return $this->readLegacyOrMovedAddonFile($relativeFromAddonRoot);
    }
}
