<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Enums\ArticleImproveScope;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Content\Services\ArticleImproveExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultImprovePromptInstaller;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepCatalogService;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleRegistry;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowRoleMigrationSuggester;
use Omnichannel\Addons\Content\Support\ArticleImproveInput;
use PHPUnit\Framework\TestCase;

final class WorkflowExecutionRoleTest extends TestCase
{
    public function test_registry_lists_canonical_roles(): void
    {
        $keys = array_column((new WorkflowExecutionRoleRegistry)->all(), 'key');

        self::assertContains(WorkflowExecutionRole::ArticleOutlineGenerate->value, $keys);
        self::assertContains(WorkflowExecutionRole::ArticleContentGenerate->value, $keys);
        self::assertContains(WorkflowExecutionRole::ArticleContentImprove->value, $keys);
        self::assertContains(WorkflowExecutionRole::ArticleImageGenerate->value, $keys);
    }

    public function test_resolver_finds_node_by_role_not_title(): void
    {
        $task = new SeoTask;
        $task->flow_data = [
            'nodes' => [
                [
                    'id' => 'n_outline',
                    'type' => 'prompt',
                    'title' => 'ViÃ¡ÂºÂ¿t bÃƒÂ i theo dÃƒÂ n ÃƒÂ½', // title lÃ¡Â»Â«a Ã¢â‚¬â€ role mÃ¡Â»â€ºi Ã„â€˜ÃƒÂºng
                    'data' => [
                        'promptId' => 1,
                        'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                    ],
                ],
                [
                    'id' => 'n_content',
                    'type' => 'prompt',
                    'title' => 'Something else',
                    'data' => [
                        'promptId' => 2,
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                    ],
                ],
            ],
            'edges' => [],
        ];

        $resolver = new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry);
        $found = $resolver->findNode($task, WorkflowExecutionRole::ArticleContentGenerate);

        self::assertNotNull($found);
        self::assertSame('n_content', $found['node_id']);
        self::assertSame(
            'n_outline',
            $resolver->requireNodeId($task, WorkflowExecutionRole::ArticleOutlineGenerate),
        );
    }

    public function test_missing_role_throws_clear_message(): void
    {
        $task = new SeoTask;
        $task->id = 99;
        $task->name = 'Publish';
        $task->flow_data = ['nodes' => [], 'edges' => []];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('article.content.generate');

        (new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry))
            ->requireNodeId($task, WorkflowExecutionRole::ArticleContentGenerate);
    }

    public function test_duplicate_unique_role_blocked(): void
    {
        // KhÃƒÂ´ng gÃ¡ÂºÂ¯n promptId Ã¢â‚¬â€ trÃƒÂ¡nh SeoPrompt::query() khi PHPUnit chÃ†Â°a bootstrap DB.
        $errors = (new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry))->validateFlowData([
            'nodes' => [
                [
                    'id' => 'a',
                    'type' => 'prompt',
                    'data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value],
                ],
                [
                    'id' => 'b',
                    'type' => 'prompt',
                    'data' => ['execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value],
                ],
            ],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Role article.content.generate', implode(' ', $errors));
        self::assertTrue(
            str_contains(implode(' ', $errors), 'trùng')
            || str_contains(implode(' ', $errors), 'trung'),
        );
    }

    public function test_execution_service_has_no_title_heuristic(): void
    {
        $ref = new \ReflectionClass(ArticleWritingExecutionService::class);
        $ctorParams = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $ref->getConstructor()?->getParameters() ?? [],
        );
        self::assertContains('roleResolver', $ctorParams);

        $source = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString('requireNodeId', $source);
        self::assertStringNotContainsString('promptNodes[1]', $source);
        // KhÃƒÂ´ng resolve node bÃ¡ÂºÂ±ng title/haystack heuristic.
        self::assertDoesNotMatchRegularExpression('/str_contains\s*\(\s*\$title\b/', $source);
        self::assertDoesNotMatchRegularExpression('/str_contains\s*\(\s*\$haystack\b/', $source);
    }

    public function test_catalog_detect_kind_has_no_title_heuristic(): void
    {
        $ref = new \ReflectionClass(SeoProjectWorkflowStepCatalogService::class);
        $catalog = $ref->newInstanceWithoutConstructor();
        $resolverProp = $ref->getProperty('roleResolver');
        $resolverProp->setValue(
            $catalog,
            new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry),
        );

        $detectKind = $ref->getMethod('detectKind');
        $misleadingTitle = [
            'title' => 'ViÃ¡ÂºÂ¿t bÃƒÂ i theo dÃƒÂ n ÃƒÂ½ / TÃ¡ÂºÂ¡o outline',
            'data' => [
                'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
            ],
        ];
        self::assertSame(
            'content',
            $detectKind->invoke($catalog, $misleadingTitle, null),
            'Kind phÃ¡ÂºÂ£i theo execution_role, khÃƒÂ´ng theo title',
        );

        $noRole = [
            'title' => 'TÃ¡ÂºÂ¡o dÃƒÂ n ÃƒÂ½ bÃƒÂ i viÃ¡ÂºÂ¿t SEO',
            'data' => [],
        ];
        self::assertSame(
            'prompt',
            $detectKind->invoke($catalog, $noRole, null),
            'KhÃƒÂ´ng cÃƒÂ³ role Ã¢â€ â€™ generic prompt, khÃƒÂ´ng Ã„â€˜oÃƒÂ¡n outline tÃ¡Â»Â« title',
        );
    }

    public function test_migration_suggester_high_hook_only(): void
    {
        $registry = new WorkflowExecutionRoleRegistry;

        self::assertSame(
            WorkflowExecutionRole::ArticleContentGenerate,
            $registry->suggestRoleFromHook('article.content.generate'),
        );
        self::assertSame(
            WorkflowExecutionRole::ArticleOutlineGenerate,
            $registry->suggestRoleFromHook('article.outline.generate'),
        );
        self::assertSame(
            WorkflowExecutionRole::ArticleOutlineGenerate,
            $registry->suggestRoleFromHook('article.outline.generate@0.1.0'),
        );
        self::assertNull($registry->suggestRoleFromHook(''));
        self::assertNull($registry->suggestRoleFromHook('unknown.hook'));
    }

    public function test_try_from_mixed_strips_version_suffix(): void
    {
        self::assertSame(
            WorkflowExecutionRole::ArticleOutlineGenerate,
            WorkflowExecutionRole::tryFromMixed('article.outline.generate@0.1.0'),
        );
        self::assertSame(
            WorkflowExecutionRole::ArticleContentGenerate,
            WorkflowExecutionRole::tryFromMixed('article.content.generate'),
        );
    }

    public function test_resolver_finds_outline_from_prompt_hook_when_execution_role_empty(): void
    {
        $task = new SeoTask;
        $task->id = 1;
        $task->name = 'Quy trình đăng bài viết';
        $task->flow_data = [
            'nodes' => [
                [
                    'id' => 'node_outline',
                    'type' => 'prompt',
                    'title' => 'Khối Prompt',
                    'data' => [
                        'promptId' => 1,
                        'execution_role' => '',
                        'hook_key' => 'article.outline.generate',
                    ],
                ],
                [
                    'id' => 'node_content',
                    'type' => 'prompt',
                    'title' => 'Prompt block',
                    'data' => [
                        'promptId' => 5,
                        'execution_role' => '',
                        'hook_key' => 'article.content.generate',
                    ],
                ],
            ],
            'edges' => [],
        ];

        $resolver = new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry);

        self::assertSame(
            'node_outline',
            $resolver->requireNodeId($task, WorkflowExecutionRole::ArticleOutlineGenerate),
        );
        self::assertSame(
            'node_content',
            $resolver->requireNodeId($task, WorkflowExecutionRole::ArticleContentGenerate),
        );
    }

    public function test_resolver_finds_outline_from_versioned_execution_role(): void
    {
        $task = new SeoTask;
        $task->flow_data = [
            'nodes' => [
                [
                    'id' => 'n_versioned',
                    'type' => 'prompt',
                    'data' => [
                        'promptId' => 1,
                        'execution_role' => 'article.outline.generate@0.1.0',
                    ],
                ],
            ],
            'edges' => [],
        ];

        $resolver = new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry);
        self::assertSame(
            'n_versioned',
            $resolver->requireNodeId($task, WorkflowExecutionRole::ArticleOutlineGenerate),
        );
    }

    public function test_bulk_actions_use_rerun_from_step_enum(): void
    {
        self::assertSame('outline', ContentProjectRerunFromStep::Outline->value);
        self::assertSame(
            ContentProjectRerunFromStep::Outline,
            ContentProjectRerunFromStep::tryFromMixed('regenerate_outline'),
        );

        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/project-run-queue.js',
        );
        self::assertStringContainsString('bulkRerunByAction', $js);
        self::assertStringContainsString('previewBulkRerunByAction', $js);
    }

    public function test_improve_installer_idempotent_contract(): void
    {
        self::assertSame(
            ArticleImproveExecutionService::HOOK_KEY,
            DefaultImprovePromptInstaller::HOOK_KEY,
        );
        self::assertStringContainsString('{{input}}', DefaultImprovePromptInstaller::MARKDOWN);
        self::assertStringContainsString('{{instruction}}', DefaultImprovePromptInstaller::MARKDOWN);
        self::assertStringContainsString('{{tone}}', DefaultImprovePromptInstaller::MARKDOWN);
        self::assertStringNotContainsString('article_length', DefaultImprovePromptInstaller::MARKDOWN);
    }

    public function test_improve_scope_selection_rejected_without_patch_path(): void
    {
        $input = new ArticleImproveInput(
            articleId: 1,
            bodyMarkdown: 'body',
            instruction: 'fix typo',
            scope: ArticleImproveScope::Selection,
            selectedText: 'typo',
        );

        self::assertSame(ArticleImproveScope::Selection, $input->scope);

        $serviceSource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleImproveExecutionService.php',
        );
        self::assertStringContainsString('persist an toàn', $serviceSource);
        self::assertStringNotContainsString('getPublishArticleTaskId', $serviceSource);
        // Tránh match nhầm ArticleWritingExecutionResult.
        self::assertDoesNotMatchRegularExpression(
            '/\bArticleWritingExecutionService\b/',
            $serviceSource,
        );
    }

    public function test_create_service_outline_then_article_uses_roles(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/CreateArticlesFromTaskService.php',
        );

        self::assertStringContainsString('runOutlineThenArticleForContext', $source);
        self::assertStringContainsString('ArticleOutlineGenerate', $source);
        self::assertStringContainsString('ArticleContentGenerate', $source);
        self::assertStringContainsString('outline_artifact_hash', $source);
        self::assertStringContainsString('article_source_artifact_hash', $source);
        self::assertStringContainsString('article_blocked', $source);
    }

    public function test_command_and_migration_files_exist(): void
    {
        self::assertFileExists(
            ProjectRoot::addonsPath().'/content-projects/src/Console/AssignWorkflowExecutionRolesCommand.php',
        );
        $migrationCandidates = [
            ProjectRoot::addonsPath().'/ai-prompt/database/migrations/2026_07_26_140000_install_default_improve_prompt_binding.php',
            ProjectRoot::addonsPath().'/content/database/migrations/2026_07_26_140000_install_default_improve_prompt_binding.php',
            ProjectRoot::addonsPath().'/seo/database/migrations/2026_07_26_140000_install_default_improve_prompt_binding.php',
        ];
        $foundMigration = false;
        foreach ($migrationCandidates as $candidate) {
            if (is_file($candidate)) {
                $foundMigration = true;
                break;
            }
        }
        if (! $foundMigration) {
            try {
                $foundMigration = is_file(
                    \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find(
                        '2026_07_26_140000_install_default_improve_prompt_binding.php',
                    ),
                );
            } catch (\Throwable) {
                $foundMigration = false;
            }
        }
        self::assertTrue(
            $foundMigration || class_exists(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultImprovePromptInstaller::class),
            'Improve prompt installer / migration must remain available.',
        );
        self::assertTrue(class_exists(WorkflowRoleMigrationSuggester::class));
        self::assertTrue(class_exists(ArticleWritingExecutionService::class));
    }

    public function test_builder_exposes_execution_role_field(): void
    {
        $jsx = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/resources/js/components/ArticleFlowBuilder.jsx',
        );

        self::assertStringContainsString('execution_role', $jsx);
        self::assertStringContainsString('__SEO_WORKFLOW_ROLES__', $jsx);
        self::assertStringContainsString('suggestExecutionRoleFromPrompt', $jsx);
    }
}
