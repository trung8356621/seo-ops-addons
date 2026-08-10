<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsCounterTransitionMap;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectDebugLifecycleAndCmUiContractTest extends TestCase
{
    public function test_debug_capability_requires_flag_and_planner_equivalent(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessControl::class))->getFileName(),
        );
        self::assertStringContainsString('function canDebugContentProjectLifecycle', $src);
        self::assertStringContainsString('debug_lifecycle_override', $src);
        self::assertStringContainsString('canManageContentProjectWorkflow', $src);
        self::assertStringContainsString('function usesContentManagerOpsPresentation', $src);
    }

    public function test_config_flag_default_off(): void
    {
        // tests/Unit â†’ SeoContentAi â†’ Addons â†’ app â†’ project root
        $path = ProjectRoot::path().DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'seo-content-ai.php';
        self::assertFileExists($path);
        $cfg = (string) file_get_contents($path);
        self::assertStringContainsString('CONTENT_PROJECT_DEBUG_LIFECYCLE_OVERRIDE', $cfg);
        self::assertStringContainsString("'debug_lifecycle_override'", $cfg);
        self::assertStringContainsString("env('CONTENT_PROJECT_DEBUG_LIFECYCLE_OVERRIDE', false)", $cfg);
    }

    public function test_debug_service_never_mentions_wordpress_publish_call(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectDebugLifecycleOverrideService.php',
        );
        self::assertStringContainsString('Never calls WordPress', $src);
        self::assertStringContainsString('DEBUG_LIFECYCLE_OVERRIDE:not_wordpress_publish', $src);
        self::assertStringContainsString('publish_published_at', $src);
        self::assertStringNotContainsString('WordPressPublisher', $src);
        self::assertStringNotContainsString('PublishNow', $src);
    }

    public function test_debug_counter_deltas_atomic(): void
    {
        self::assertSame(
            ['published' => -1, 'scheduled' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(
                ContentProjectOpsCounterTransitionMap::ACTION_DEBUG_PUBLISHED_TO_SCHEDULED,
            ),
        );
        self::assertSame(
            ['published' => -1, 'approved' => 1],
            ContentProjectOpsCounterTransitionMap::deltas(
                ContentProjectOpsCounterTransitionMap::ACTION_DEBUG_PUBLISHED_TO_APPROVED,
            ),
        );
        self::assertSame(
            ContentProjectOpsCounterTransitionMap::ACTION_DEBUG_PUBLISHED_TO_SCHEDULED,
            ContentProjectOpsCounterTransitionMap::debugAction('published', 'scheduled'),
        );
    }

    public function test_command_bus_registers_debug_override(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php',
        );
        self::assertStringContainsString('DebugOverrideProjectItemLifecycleCommand', $src);
        self::assertStringContainsString('DebugOverrideProjectItemLifecycleHandler', $src);
        self::assertStringContainsString('CancelProjectItemPublishingCommand', $src);
    }

    public function test_presenter_exposes_debug_flags_keys(): void
    {
        $flags = ContentProjectItemActionsPresenter::forRow([
            'lifecycle' => 'published',
            'queue_status' => 'published',
            'generation_badge' => ['key' => 'success'],
            'article_edit_url' => '/x',
            'generation_status' => 'completed',
        ]);
        self::assertArrayHasKey('debug_to_scheduled', $flags);
        self::assertArrayHasKey('debug_to_approved', $flags);
        self::assertArrayHasKey('has_debug', $flags);
        // Flag off / no auth â†’ debug false
        self::assertFalse($flags['debug_to_scheduled']);
        self::assertFalse($flags['has_debug']);
    }

    public function test_cm_ops_ui_contracts_in_blade(): void
    {
        $ops = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/view-seo-project-operations.blade.php'),
        );
        self::assertStringContainsString('usesContentManagerOpsPresentation', $ops);
        self::assertStringContainsString('ops_needs_review_empty', $ops);
        self::assertStringContainsString('cp-ops-debug-lifecycle', $ops);
        self::assertStringContainsString('debugLifecycleOne', $ops);

        $toolbar = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-filter-toolbar.blade.php'),
        );
        self::assertStringContainsString('contentManager', $toolbar);
        self::assertStringContainsString('@unless ($contentManager)', $toolbar);
        self::assertStringContainsString('ops_needs_review', $toolbar);
        self::assertMatchesRegularExpression('/@unless \(\$contentManager\)[\s\S]*?value="pending"/', $toolbar);
        self::assertMatchesRegularExpression('/@unless \(\$contentManager\)[\s\S]*?value="failed"/', $toolbar);

        $menu = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-actions-menu.blade.php'),
        );
        self::assertStringContainsString('cp-ops-menu__label', $menu);
        // `.cp-ops-menu` sizing rules live in the shared ops-styles component now.
        $styles = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-ops-styles.blade.php'),
        );
        self::assertStringContainsString('white-space: nowrap', $styles);
        self::assertStringContainsString('min-width: 240px', $styles);
        self::assertStringContainsString('Debug lifecycle', $menu);
        self::assertStringContainsString('position:fixed', $menu);
    }

    public function test_handoff_service_still_canonical_for_cm_save(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectContentManagerHandoffService.php',
        );
        self::assertStringContainsString('canSubmitArticleReview', $src);
        self::assertStringContainsString('ORIGIN_ARTICLE_EDITOR', $src);
        self::assertStringContainsString('content_manager_reviewed_at', $src);
        self::assertStringNotContainsString('SubmitReview', $src);
    }
}
