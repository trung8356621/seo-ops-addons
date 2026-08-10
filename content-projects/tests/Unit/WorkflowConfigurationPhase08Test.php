<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Console\WorkflowDoctorCommand;
use Omnichannel\Addons\Content\Contracts\SeoCreateArticleSettingsReader;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowCapability;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Content\Services\ArticleImproveExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWritingExecutionService;
use Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowAssignmentValidator;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowDoctorService;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleRegistry;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionSnapshotBuilder;
use Omnichannel\Addons\ContentProjects\Support\WorkflowExecutionSnapshot;
use PHPUnit\Framework\TestCase;

final class WorkflowConfigurationPhase08Test extends TestCase
{
    public function test_publish_capability_requires_outline_and_content(): void
    {
        $roles = WorkflowCapability::PublishArticle->requiredRoles();
        self::assertContains(WorkflowExecutionRole::ArticleOutlineGenerate, $roles);
        self::assertContains(WorkflowExecutionRole::ArticleContentGenerate, $roles);
    }

    public function test_content_only_requires_content_not_outline(): void
    {
        $roles = WorkflowCapability::ContentOnly->requiredRoles();
        self::assertSame([WorkflowExecutionRole::ArticleContentGenerate], $roles);
        self::assertNotContains(WorkflowExecutionRole::ArticleOutlineGenerate, $roles);
    }

    public function test_improve_capability_requires_improve_role(): void
    {
        self::assertSame(
            [WorkflowExecutionRole::ArticleContentImprove],
            WorkflowCapability::Improve->requiredRoles(),
        );
    }

    public function test_media_capabilities_do_not_hard_require_image_role(): void
    {
        self::assertSame([], WorkflowCapability::ProductGallery->requiredRoles());
        self::assertSame([], WorkflowCapability::TypographyImage->requiredRoles());
        self::assertSame([], WorkflowCapability::CreateVideo->requiredRoles());
    }

    public function test_assignment_validator_detects_missing_publish_roles(): void
    {
        $task = new SeoTask;
        $task->id = 7;
        $task->name = 'Thin Publish';
        $task->flow_data = [
            'nodes' => [
                [
                    'id' => 'only_content',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                        'promptId' => 1,
                    ],
                ],
            ],
            'edges' => [],
        ];

        $validator = $this->assignmentValidatorWithoutDb();
        $errors = $validator->validateTaskForCapability($task, WorkflowCapability::PublishArticle);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('article.outline.generate', implode(' ', $errors));
        self::assertStringContainsString('Thin Publish', implode(' ', $errors));
    }

    public function test_assignment_validator_content_only_ok_without_outline(): void
    {
        $task = new SeoTask;
        $task->id = 8;
        $task->name = 'Content Only';
        $task->flow_data = [
            'nodes' => [
                [
                    'id' => 'c',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                        'promptId' => 1,
                    ],
                ],
            ],
            'edges' => [],
        ];

        $errors = $this->assignmentValidatorWithoutDb()
            ->validateTaskForCapability($task, WorkflowCapability::ContentOnly);

        self::assertSame([], $errors);
    }

    public function test_builder_save_blocks_duplicate_role(): void
    {
        $errors = (new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry))->validateFlowData([
            'nodes' => [
                [
                    'id' => 'a',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                        'promptId' => 1,
                    ],
                ],
                [
                    'id' => 'b',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                        'promptId' => 2,
                    ],
                ],
            ],
            'edges' => [],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('trÃ¹ng', implode(' ', $errors));
    }

    public function test_builder_save_blocks_broken_edge(): void
    {
        $errors = (new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry))->validateFlowData([
            'nodes' => [
                [
                    'id' => 'alive',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                        'promptId' => 1,
                    ],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'alive', 'target' => 'ghost'],
            ],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('ghost', implode(' ', $errors));
    }

    public function test_builder_save_blocks_role_without_prompt(): void
    {
        $errors = (new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry))->validateFlowData([
            'nodes' => [
                [
                    'id' => 'n1',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                    ],
                ],
            ],
            'edges' => [],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Prompt', implode(' ', $errors));
    }

    public function test_settings_binding_preserve_blocks_removing_content_role(): void
    {
        $task = new SeoTask;
        $task->id = 42;
        $task->name = 'Publish WF';
        $task->flow_data = ['nodes' => [], 'edges' => []];

        $settings = new class implements SeoCreateArticleSettingsReader
        {
            public function getSettings(): array
            {
                return [
                    'publish_article_task_id' => 42,
                ];
            }

            public function getPublishArticleTaskId(): ?int
            {
                return 42;
            }
        };

        $validator = new WorkflowAssignmentValidator(
            new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry),
            $settings,
        );

        $errors = $validator->validateFlowPreservesSettingsBindings($task, [
            'nodes' => [
                [
                    'id' => 'outline_only',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                        'promptId' => 1,
                    ],
                ],
            ],
            'edges' => [],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('article.content.generate', implode(' ', $errors));
        self::assertStringContainsString('Settings', implode(' ', $errors));
    }

    public function test_run_snapshot_keeps_flow_hash_and_roles(): void
    {
        $task = new SeoTask;
        $task->id = 11;
        $task->name = 'Snap WF';
        $task->flow_data = [
            'nodes' => [
                [
                    'id' => 'n_out',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                        'promptId' => 5,
                    ],
                ],
                [
                    'id' => 'n_content',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                        'promptId' => 6,
                    ],
                ],
            ],
            'edges' => [['id' => 'e', 'source' => 'n_out', 'target' => 'n_content']],
        ];

        $snap = (new WorkflowExecutionSnapshotBuilder(
            new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry),
        ))->fromTask($task);

        self::assertSame(11, $snap->workflowId);
        self::assertNotSame('', $snap->flowDataHash);
        self::assertSame(64, strlen($snap->flowDataHash));
        self::assertSame('n_content', $snap->nodeIdForRole(WorkflowExecutionRole::ArticleContentGenerate->value));
        self::assertSame(6, $snap->promptIdForNode('n_content'));

        $roundTrip = WorkflowExecutionSnapshot::tryFromArray($snap->toArray());
        self::assertNotNull($roundTrip);
        self::assertSame($snap->flowDataHash, $roundTrip->flowDataHash);

        // Äá»•i flow â†’ hash má»›i (run cÅ© giá»¯ hash cÅ©).
        $task->flow_data = [
            'nodes' => [
                [
                    'id' => 'n_content',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                        'promptId' => 99,
                    ],
                ],
            ],
            'edges' => [],
        ];
        $newSnap = (new WorkflowExecutionSnapshotBuilder(
            new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry),
        ))->fromTask($task);
        self::assertNotSame($snap->flowDataHash, $newSnap->flowDataHash);
        self::assertSame(99, $newSnap->promptIdForNode('n_content'));
    }

    public function test_retry_uses_snapshot_node_not_live_lookup(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleWritingExecutionService.php',
        );

        self::assertStringContainsString('workflow_execution_snapshot', $source);
        self::assertStringContainsString('KhÃ´ng thá»ƒ thá»­ láº¡i láº§n cháº¡y cÅ©', $source);
        self::assertStringContainsString('Cháº¡y láº¡i báº±ng cáº¥u hÃ¬nh hiá»‡n táº¡i', $source);
    }

    public function test_doctor_command_and_service_exist(): void
    {
        self::assertTrue(class_exists(WorkflowDoctorCommand::class));
        self::assertTrue(class_exists(WorkflowDoctorService::class));
        $cmd = new \ReflectionClass(WorkflowDoctorCommand::class);
        $props = $cmd->getDefaultProperties();
        self::assertStringContainsString('seo:workflow:doctor', (string) ($props['signature'] ?? ''));
    }

    public function test_doctor_detects_duplicate_and_missing_via_resolver(): void
    {
        $resolver = new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry);
        $dup = $resolver->validateFlowData([
            'nodes' => [
                [
                    'id' => 'a',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                        'promptId' => 1,
                    ],
                ],
                [
                    'id' => 'b',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleOutlineGenerate->value,
                        'promptId' => 2,
                    ],
                ],
            ],
            'edges' => [],
        ]);
        self::assertStringContainsString('trÃ¹ng', implode(' ', $dup));

        $task = new SeoTask;
        $task->id = 3;
        $task->flow_data = [
            'nodes' => [
                [
                    'id' => 'c',
                    'type' => 'prompt',
                    'data' => [
                        'execution_role' => WorkflowExecutionRole::ArticleContentGenerate->value,
                        'promptId' => 1,
                    ],
                ],
            ],
            'edges' => [],
        ];
        $missing = $this->assignmentValidatorWithoutDb()
            ->validateTaskForCapability($task, WorkflowCapability::PublishArticle);
        self::assertStringContainsString('outline', implode(' ', $missing));
    }

    public function test_improve_isolated_from_generate(): void
    {
        self::assertSame('article.content.improve', ArticleImproveExecutionService::HOOK_KEY);
        self::assertNotSame(ArticleWritingExecutionService::HOOK_KEY, ArticleImproveExecutionService::HOOK_KEY);
        $improve = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleImproveExecutionService.php',
        );
        self::assertDoesNotMatchRegularExpression('/\bArticleWritingExecutionService\b/', $improve);
    }

    public function test_legacy_adapter_exposes_observability_log(): void
    {
        $ref = new \ReflectionClass(ArticleWritingLegacyRewriteAdapter::class);
        self::assertTrue($ref->hasMethod('logLegacyAdapterUsed'));
        $src = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString('article_writing.legacy_adapter_used', $src);
    }

    public function test_runtime_has_no_title_heuristic_in_catalog(): void
    {
        $catalog = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowStepCatalogService.php',
        );
        self::assertDoesNotMatchRegularExpression('/str_contains\s*\(\s*\$haystack\b/', $catalog);
        self::assertStringContainsString('catalogKind()', $catalog);
    }

    public function test_settings_save_validates_assignment(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/src/Filament/Pages/SeoSettingsWorkflows.php',
        );
        self::assertStringContainsString('WorkflowAssignmentValidator', $src);
        self::assertStringContainsString('validatePendingSettings', $src);
        self::assertStringContainsString('workflowHealthHtml', $src);
        self::assertStringContainsString('open_workflow_builder', $src);
    }

    public function test_run_service_captures_workflow_snapshot(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php',
        );
        self::assertStringContainsString('workflow_execution_snapshot', $src);
        self::assertStringContainsString('capturePublishWorkflowSnapshot', $src);
    }

    private function assignmentValidatorWithoutDb(): WorkflowAssignmentValidator
    {
        $settings = new class implements SeoCreateArticleSettingsReader
        {
            public function getSettings(): array
            {
                return [];
            }

            public function getPublishArticleTaskId(): ?int
            {
                return null;
            }
        };

        return new WorkflowAssignmentValidator(
            new WorkflowExecutionRoleResolver(new WorkflowExecutionRoleRegistry),
            $settings,
        );
    }
}
