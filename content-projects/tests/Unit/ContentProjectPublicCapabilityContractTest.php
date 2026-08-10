<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemArchiveState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemGenerationState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemPublishState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemReviewState;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectLifecyclePhase;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentCommandFactory;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionGuard;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemStateResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Batch E â€” public capability exposure contract (agent vs MCP) + Option B
 * item-restore removal. Pure PHPUnit â€” no Laravel boot / ExtensionStateStore.
 */
final class ContentProjectPublicCapabilityContractTest extends TestCase
{
    public function test_every_agent_exposed_content_project_write_has_command_factory_arm(): void
    {
        $registry = new ContentProjectCapabilityRegistry;
        $factorySource = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentCommandFactory::class))->getFileName(),
        );

        $checked = 0;
        foreach ($registry->all() as $cap) {
            $name = (string) ($cap['name'] ?? '');
            if (! str_starts_with($name, 'content_project.')) {
                continue;
            }

            if (! $this->isAgentExposedWriteCap($cap)) {
                continue;
            }

            self::assertStringContainsString(
                "'".$name."' =>",
                $factorySource,
                'ContentProjectAgentCommandFactory missing match arm for '.$name,
            );
            $checked++;
        }

        self::assertGreaterThan(0, $checked);
    }

    public function test_agent_exposed_write_caps_without_factory_arm_count_is_zero(): void
    {
        $registry = new ContentProjectCapabilityRegistry;
        $factorySource = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentCommandFactory::class))->getFileName(),
        );

        $violations = [];
        foreach ($registry->all() as $cap) {
            $name = (string) ($cap['name'] ?? '');
            if (! str_starts_with($name, 'content_project.') && ! str_starts_with($name, 'keyword_intelligence.')) {
                continue;
            }

            if (! $this->isAgentExposedWriteCap($cap)) {
                continue;
            }

            if (! str_contains($factorySource, "'".$name."' =>")) {
                $violations[] = $name;
            }
        }

        self::assertSame([], $violations, 'Agent-exposed write caps without factory arm: '.implode(', ', $violations));
    }

    public function test_archive_items_capability_is_agent_and_mcp_exposed_with_factory_arm(): void
    {
        $registry = new ContentProjectCapabilityRegistry;
        $cap = $registry->get('content_project.archive_items');

        self::assertNotNull($cap);
        self::assertTrue((bool) ($cap['confirmation_requirement'] ?? false));
        self::assertSame('content_project.archive_items', $cap['required_permission'] ?? null);
        self::assertContains('content_project.archive_items', $cap['scopes'] ?? []);
        self::assertStringContainsString('ArchiveProjectItemsCommand', (string) ($cap['handler'] ?? ''));
        self::assertTrue($registry->isAgentWriteExposed('content_project.archive_items'));
        self::assertTrue($registry->isMcpWriteExposed('content_project.archive_items'));

        $factorySource = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectAgentCommandFactory::class))->getFileName(),
        );
        self::assertStringContainsString("'content_project.archive_items' =>", $factorySource);
        self::assertStringContainsString('ArchiveProjectItemsCommand', $factorySource);
    }

    public function test_mcp_exposure_policy_excludes_sync_stop_resume_and_serp_gsc_writes(): void
    {
        $catalogSource = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMcpToolCatalog::class))->getFileName(),
        );
        self::assertStringContainsString('isMcpWriteExposed', $catalogSource);

        $registry = new ContentProjectCapabilityRegistry;
        $registrySource = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectCapabilityRegistry::class))->getFileName(),
        );
        self::assertStringContainsString('MCP_EXCLUDED_NAMES', $registrySource);
        self::assertStringContainsString('serp_intelligence.', $registrySource);
        self::assertStringContainsString('gsc_intelligence.', $registrySource);

        self::assertTrue($registry->isAgentWriteExposed('content_project.stop_execution'));
        self::assertTrue($registry->isAgentWriteExposed('content_project.resume_execution'));
        self::assertFalse($registry->isMcpWriteExposed('content_project.stop_execution'));
        self::assertFalse($registry->isMcpWriteExposed('content_project.resume_execution'));
        self::assertFalse($registry->isAgentWriteExposed('content_project.sync_items'));
        self::assertFalse($registry->isMcpWriteExposed('content_project.sync_items'));

        self::assertTrue($registry->isMcpWriteExposed('content_project.generate'));
        self::assertTrue($registry->isMcpWriteExposed('content_project.archive_items'));

        // SERP/GSC writes registered but MCP-blocked by prefix.
        self::assertFalse($registry->isMcpWriteExposed('serp_intelligence.create_queries'));
        self::assertFalse($registry->isMcpWriteExposed('gsc_intelligence.sync_performance'));
    }

    public function test_item_action_guard_offers_no_restore_for_content_archived(): void
    {
        $enumSource = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemAction::class))->getFileName(),
        );
        self::assertStringNotContainsString("case Restore = 'restore';", $enumSource);

        $guardSource = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemActionGuard::class))->getFileName(),
        );
        self::assertStringNotContainsString('return [ContentProjectItemAction::Restore];', $guardSource);

        $guard = new ContentProjectItemActionGuard;
        $actions = $guard->availableActions(
            lifecycle: ContentProjectLifecyclePhase::Archived,
            archive: ContentProjectItemArchiveState::ContentArchived,
            publish: ContentProjectItemPublishState::None,
            generation: ContentProjectItemGenerationState::Idle,
            review: ContentProjectItemReviewState::None,
            hasPublished: false,
        );
        self::assertSame([], $actions);

        $resolver = new ContentProjectItemStateResolver($guard);
        $task = new SeoProjectTask;
        $task->setRawAttributes(['status' => 'completed', 'archived_at' => '2026-07-01 12:00:00'], true);
        $state = $resolver->resolve($task);
        self::assertSame(ContentProjectItemArchiveState::ContentArchived, $state->archiveState);
        self::assertSame([], $state->availableActions);
    }

    public function test_item_action_guard_allows_rerun_for_draft_generation_failed(): void
    {
        $guard = new ContentProjectItemActionGuard;
        $actions = $guard->availableActions(
            lifecycle: ContentProjectLifecyclePhase::Draft,
            archive: ContentProjectItemArchiveState::None,
            publish: ContentProjectItemPublishState::None,
            generation: ContentProjectItemGenerationState::Failed,
            review: ContentProjectItemReviewState::None,
            hasPublished: false,
        );

        self::assertContains(ContentProjectItemAction::Generate, $actions);
        self::assertContains(ContentProjectItemAction::Rerun, $actions);
    }

    public function test_bulk_rerun_service_file_does_not_exist(): void
    {
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProjectBulkRerunService.php',
        );
    }

    public function test_rerun_article_pipeline_job_file_does_not_exist(): void
    {
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content/src/Jobs/RerunArticlePipelineJob.php',
        );
    }

    public function test_content_project_step_rerun_service_file_does_not_exist(): void
    {
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProjectStepRerunService.php',
        );
    }

    public function test_seo_article_model_has_no_is_reviewed_column_reference(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoArticle::class))->getFileName(),
        );
        self::assertStringNotContainsString('is_reviewed', $source);
    }

    public function test_item_state_resolver_does_not_read_project_status(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemStateResolver::class))->getFileName(),
        );
        self::assertStringNotContainsString('project->status', $source);
        self::assertStringNotContainsString('SeoProject::STATUS', $source);
    }

    public function test_legacy_ui_booleans_keys_still_present_from_available_actions_pattern(): void
    {
        $ops = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectItemOperationsReadModel.php',
        );
        self::assertStringContainsString("ContentProjectItemAction::Generate, \$state->availableActions", $ops);
        self::assertStringContainsString("ContentProjectItemAction::Rerun, \$state->availableActions", $ops);
        self::assertStringNotContainsString('ContentProjectItemGenerationClassifier', $ops);
    }

    /**
     * @param  array<string, mixed>  $cap
     */
    private function isAgentExposedWriteCap(array $cap): bool
    {
        if ((bool) ($cap['internal'] ?? false)) {
            return false;
        }

        if (! (bool) ($cap['agent_exposed'] ?? true)) {
            return false;
        }

        if (($cap['risk_level'] ?? '') === 'read' || (bool) ($cap['read_only'] ?? false)) {
            return false;
        }

        return true;
    }
}
