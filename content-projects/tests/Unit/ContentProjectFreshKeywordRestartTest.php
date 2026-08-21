<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\TaskTestInputResolver;
use Omnichannel\Addons\AiPrompt\Services\TaskWorkflowTestRunner;
use Omnichannel\Addons\Content\Services\ArticleWritingAssembler;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\RestartGenerationWithKeywordCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\RestartGenerationWithKeywordHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFreshKeywordWorkspaceResetService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectFreshKeywordRestart;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionCatalog;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class ContentProjectFreshKeywordRestartTest extends TestCase
{
    public function test_command_and_handler_are_registered(): void
    {
        $registrar = (string) file_get_contents((new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName());
        self::assertStringContainsString('RestartGenerationWithKeywordCommand::class', $registrar);
        self::assertStringContainsString('RestartGenerationWithKeywordHandler::class', $registrar);

        $command = new RestartGenerationWithKeywordCommand(1, [10], 'vali cần kéo du lịch màu đỏ');
        self::assertSame('content_project.restart_with_keyword', $command->name());
    }

    public function test_fresh_keyword_context_stamps_override_and_isolation_flags(): void
    {
        $support = (string) file_get_contents((new ReflectionClass(ContentProjectFreshKeywordRestart::class))->getFileName());
        self::assertStringContainsString("'generation_mode'", $support);
        self::assertStringContainsString("'generation_keyword_override'", $support);
        self::assertStringContainsString("'inherit_previous_generation'", $support);
        self::assertStringContainsString("'use_rewrite_source_content'", $support);

        $resolver = (string) file_get_contents((new ReflectionClass(TaskTestInputResolver::class))->getFileName());
        self::assertStringContainsString('function resolveFreshKeywordRestartForProjectTask', $resolver);
        self::assertStringContainsString('ContentProjectFreshKeywordRestart::stampVariables', $resolver);
        self::assertStringContainsString('withArticle($article, false, \'id\')', $resolver);
        self::assertStringNotContainsString('resolveExistingArticleRewrite(', substr(
            $resolver,
            (int) strpos($resolver, 'function resolveFreshKeywordRestartForProjectTask'),
            2500,
        ));
        self::assertStringNotContainsString('contextFromArticle($article', substr(
            $resolver,
            (int) strpos($resolver, 'function resolveFreshKeywordRestartForProjectTask'),
            2500,
        ));
    }

    public function test_workflow_run_service_uses_fresh_keyword_resolver(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SeoProjectWorkflowRunService::class))->getFileName());
        self::assertStringContainsString('resolveFreshKeywordRestartForProjectTask', $source);
        self::assertStringContainsString('ContentProjectFreshKeywordRestart::isRunSettings', $source);
        self::assertStringContainsString('generation_keyword_override', $source);
    }

    public function test_runner_and_assembler_block_inherited_artifacts(): void
    {
        $runner = (string) file_get_contents((new ReflectionClass(TaskWorkflowTestRunner::class))->getFileName());
        self::assertStringContainsString('shouldBlockInheritedGenerationArtifacts', $runner);
        self::assertStringContainsString('ContentProjectFreshKeywordRestart::isActive', $runner);

        $assembler = (string) file_get_contents((new ReflectionClass(ArticleWritingAssembler::class))->getFileName());
        self::assertStringContainsString('shouldUseRewriteSourceContent', $assembler);
    }

    public function test_handler_preserves_item_identity_and_resets_workspace_only(): void
    {
        $handler = (string) file_get_contents((new ReflectionClass(RestartGenerationWithKeywordHandler::class))->getFileName());
        self::assertStringContainsString('ContentProjectFreshKeywordWorkspaceResetService', $handler);
        self::assertStringContainsString('resetForTask', $handler);
        self::assertStringContainsString('validateFull', $handler);
        self::assertStringNotContainsString('commitCanonicalKeyword', $handler);
        self::assertStringNotContainsString('fresh_create', $handler);

        $reset = (string) file_get_contents((new ReflectionClass(ContentProjectFreshKeywordWorkspaceResetService::class))->getFileName());
        self::assertStringContainsString('ArticleAiHistoryPendingDraftStore::META_KEY', $reset);
        self::assertStringNotContainsString('SeoPromptResult', $reset);
    }

    public function test_canonical_keyword_commits_only_via_success_helper(): void
    {
        $support = (string) file_get_contents((new ReflectionClass(ContentProjectFreshKeywordRestart::class))->getFileName());
        self::assertStringContainsString('function commitCanonicalKeyword', $support);
        self::assertStringContainsString('seo_focus_keyword', $support);

        $workflow = (string) file_get_contents((new ReflectionClass(SeoProjectWorkflowRunService::class))->getFileName());
        self::assertStringContainsString('commitCanonicalKeyword', $workflow);
        self::assertStringContainsString('isFreshKeywordRestart', $workflow);
    }

    public function test_run_settings_snapshot_keeps_generation_mode_and_keyword(): void
    {
        $snapshot = ContentProjectRunSettings::snapshotForRun([
            'generation_mode' => ContentProjectFreshKeywordRestart::MODE,
            'generation_keyword_override' => 'vali cần kéo du lịch màu đỏ',
            'task_ids' => [1],
            'rerun' => true,
            'rerun_scope' => 'full',
        ]);

        self::assertSame(ContentProjectFreshKeywordRestart::MODE, $snapshot['generation_mode']);
        self::assertSame('vali cần kéo du lịch màu đỏ', $snapshot['generation_keyword_override']);
    }

    public function test_rewrite_item_context_keeps_task_type_and_article_link_semantics(): void
    {
        $resolverPath = ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskTestInputResolver.php';
        $resolverSource = (string) file_get_contents($resolverPath);
        $start = (int) strpos($resolverSource, 'function resolveFreshKeywordRestartForProjectTask');
        $chunk = substr($resolverSource, $start, 3500);

        self::assertStringContainsString('withProjectTaskType($type)', $chunk);
        self::assertStringContainsString('withArticle($article, false, \'id\')', $chunk);
        self::assertStringNotContainsString('withRewriteOptions', $chunk);
        self::assertStringNotContainsString('applyOutlineFromArticle', $chunk);
    }

    public function test_generation_lock_rejects_active_execution(): void
    {
        $handler = (string) file_get_contents((new ReflectionClass(RestartGenerationWithKeywordHandler::class))->getFileName());
        self::assertStringContainsString('ACTION_ACTIVE', $handler);
        self::assertStringContainsString('OPERATION_ALREADY_PROCESSING', $handler);
        self::assertStringContainsString('hasConflictingActiveExecution', $handler);
    }

    public function test_ui_action_modal_and_keyword_required(): void
    {
        $actions = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'failed',
            'generation_status' => 'failed',
            'generation_badge' => ['key' => 'failed'],
            'can_generate' => true,
            'can_regen' => true,
            'is_genuinely_running' => false,
            'article_edit_url' => '/seo/articles/1/edit',
        ]);
        self::assertTrue($actions['restart_with_keyword']);

        $def = ContentProjectItemActionCatalog::definition('restart_with_keyword');
        self::assertNotNull($def);
        self::assertSame('openRestartWithKeyword', $def['single_method']);
        self::assertSame([RestartGenerationWithKeywordCommand::class], $def['command_family']);

        $page = (string) file_get_contents((new ReflectionClass(ViewSeoProject::class))->getFileName());
        self::assertStringContainsString('function openRestartWithKeyword', $page);
        self::assertStringContainsString('function confirmRestartWithKeyword', $page);
        self::assertStringContainsString('RestartGenerationWithKeywordCommand', $page);
        self::assertStringContainsString('trim($command->keyword)', (string) file_get_contents((new ReflectionClass(RestartGenerationWithKeywordHandler::class))->getFileName()));

        $menu = (string) file_get_contents(LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'));
        self::assertStringContainsString('restart_with_keyword', $menu);
        self::assertStringContainsString('open-restart-with-keyword', $menu);

        $ops = (string) file_get_contents(LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'));
        self::assertStringContainsString('restartKeywordOpen', $ops);
        self::assertStringContainsString('confirmRestartWithKeyword', $ops);
        self::assertStringContainsString('restart_with_keyword_input_label', $ops);
        self::assertStringContainsString('pollRestartWithKeywordStatus', $ops);
        self::assertStringContainsString('waitRestartKeywordTerminal', $ops);
        self::assertStringNotContainsString('$wire.openRestartWithKeyword', $ops);

        $vi = (string) file_get_contents(LegacyAddonPath::resolve('lang/vi/filament.php'));
        self::assertStringContainsString("'item_action_restart_with_keyword' => 'Chạy lại với từ khóa'", $vi);
        self::assertStringContainsString("'restart_with_keyword_submit' => 'Chạy lại'", $vi);
    }

    /**
     * Regression: fresh_keyword_restart must preserve structural context.
     * post_type must be resolved canonically, article_length must NOT fallback to 2000
     * merely because it's a fresh restart.
     */
    public function test_stamp_variables_preserves_structural_context(): void
    {
        $postType = 'product';
        $keyword = 'vali cần kéo du lịch màu đỏ';
        $articleLength = '1200';

        $variables = [
            'post_type' => $postType,
            '_project_post_type' => $postType,
            'article_length' => $articleLength,
            'article_length_product' => '1000',
            'article_length_default' => '2000',
            'tone' => 'professional',
            'keyword_density' => 'natural',
        ];

        $stamped = ContentProjectFreshKeywordRestart::stampVariables($variables, $keyword);

        // Structural context MUST survive stampVariables
        self::assertSame($postType, $stamped['post_type'], 'post_type must be preserved');
        self::assertSame($postType, $stamped['_project_post_type'], '_project_post_type must be preserved');
        self::assertSame($articleLength, $stamped['article_length'], 'article_length must be preserved (not reset to 2000)');
        self::assertSame('1000', $stamped['article_length_product'], 'article_length_product must be preserved');
        self::assertSame('2000', $stamped['article_length_default'], 'article_length_default must be preserved');
        self::assertSame('professional', $stamped['tone'], 'tone must be preserved');

        // Semantic context must be replaced
        self::assertSame($keyword, $stamped['focus_keyword']);
        self::assertSame($keyword, $stamped['keyword']);
        self::assertSame($keyword, $stamped['topic']);
        self::assertSame($keyword, $stamped['post_title']);
        self::assertSame($keyword, $stamped['title']);

        // Old generated context must be absent
        self::assertArrayNotHasKey('article_writing_raw_input', $stamped);
        self::assertArrayNotHasKey('article_writing_formatted', $stamped);
        self::assertArrayNotHasKey('outline_id', $stamped);
        self::assertArrayNotHasKey('input', $stamped);
        self::assertArrayNotHasKey('post_content', $stamped);
        self::assertArrayNotHasKey('legacy_rewrite_adapter', $stamped);

        // Isolation flags
        self::assertSame('false', $stamped['inherit_previous_generation']);
        self::assertSame('false', $stamped['use_existing_article_content']);
        self::assertSame('false', $stamped['use_existing_outline']);
        self::assertSame('false', $stamped['use_rewrite_source_content']);
    }

    /**
     * Regression: resolveCanonicalPostType prefers task.post_type, falls back to article.
     */
    public function test_resolve_canonical_post_type_method_exists_and_uses_article_fallback(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(TaskTestInputResolver::class))->getFileName(),
        );

        // Method must exist
        self::assertStringContainsString('function resolveCanonicalPostType', $src);

        // Must reference ArticlePostTypeResolver for fallback
        self::assertStringContainsString('ArticlePostTypeResolver::resolve', $src);

        // Fresh restart path must use resolveCanonicalPostType
        self::assertStringContainsString('$this->resolveCanonicalPostType($task, $article)', $src);

        // post_type variable must be explicitly set before stampVariables
        $freshMethod = $this->extractMethodBody($src, 'resolveFreshKeywordRestartForProjectTask');
        self::assertNotNull($freshMethod, 'Method resolveFreshKeywordRestartForProjectTask must exist');

        $stampPos = strpos($freshMethod, 'stampVariables');
        $postTypeVarPos = strpos($freshMethod, "\$variables['post_type'] = \$postType");
        self::assertNotFalse($postTypeVarPos, 'post_type variable must be set in fresh restart path');
        self::assertLessThan($stampPos, $postTypeVarPos, 'post_type must be set BEFORE stampVariables');
    }

    private function extractMethodBody(string $source, string $methodName): ?string
    {
        $pos = strpos($source, "function {$methodName}(");
        if ($pos === false) {
            return null;
        }
        $braceStart = strpos($source, '{', $pos);
        if ($braceStart === false) {
            return null;
        }
        $depth = 0;
        $len = strlen($source);
        for ($i = $braceStart; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $braceStart, $i - $braceStart + 1);
                }
            }
        }
        return null;
    }
}
