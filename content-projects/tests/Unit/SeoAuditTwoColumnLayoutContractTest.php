<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * SEO Audit Content Planning: Improve + Site Planning only; Idea Manager via in-page CTA.
 */
final class SeoAuditTwoColumnLayoutContractTest extends TestCase
{
    public function test_draft_planner_renders_two_columns_without_ideas_panel(): void
    {
        $draft = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        $css = LegacyAddonPath::read('resources/views/components/content-project-ops-styles.blade.php');

        self::assertStringContainsString('data-planner-card="improve"', $draft);
        self::assertStringContainsString('data-planner-card="site-planning"', $draft);
        self::assertStringContainsString('content-project-site-planning', $draft);
        self::assertStringContainsString('data-planner-layout="two-column"', $draft);
        self::assertStringContainsString('data-planner-action="idea-manager"', $draft);
        self::assertStringContainsString('ContentProjectIdeaManager::getUrl()', $draft);
        self::assertStringContainsString('idea_manager_nav_label', $draft);

        self::assertStringNotContainsString('data-planner-card="create"', $draft);
        self::assertStringNotContainsString('data-create-tab="ideas"', $draft);
        self::assertStringNotContainsString('data-create-tab="ai"', $draft);
        self::assertStringNotContainsString('content-project-idea-candidate-picker', $draft);
        self::assertStringNotContainsString('content-project-new-content-card', $draft);
        self::assertStringNotContainsString('createTab', $draft);
        self::assertStringNotContainsString('plannerLayout', $draft);

        self::assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\)\s*\{\s*\.cp-plan-grid\s*\{\s*grid-template-columns:\s*minmax\(0,\s*1fr\)\s+minmax\(0,\s*1fr\)/',
            $css,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.cp-plan-grid\s*\{\s*grid-template-columns:\s*repeat\(3,/',
            $css,
        );
    }

    public function test_idea_manager_stays_routable_without_sidebar_nav(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName(),
        );

        self::assertStringContainsString("slug = 'content-projects/idea-manager'", $src);
        self::assertStringContainsString('shouldRegisterNavigation = false', $src);
        self::assertStringContainsString('canManageContentProjectWorkflow', $src);
    }

    public function test_idea_and_generate_components_remain_available(): void
    {
        self::assertFileExists(
            LegacyAddonPath::resolve('resources/views/components/content-project-idea-candidate-picker.blade.php'),
        );
        self::assertFileExists(
            LegacyAddonPath::resolve('resources/views/components/content-project-new-content-card.blade.php'),
        );
        self::assertFileExists(
            LegacyAddonPath::resolve('resources/views/components/content-project-audit-notes.blade.php'),
        );
        $auditNotes = LegacyAddonPath::read('resources/views/components/content-project-audit-notes.blade.php');
        self::assertStringContainsString('seoOps:content-planner:audit-notes:v2:site:', $auditNotes);
    }
}
