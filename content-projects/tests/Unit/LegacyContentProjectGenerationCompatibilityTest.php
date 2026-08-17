<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\ContentProjects\Console\RepairLegacyContentProjectGenerationCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\LegacyContentProjectItemHydrator;
use PHPUnit\Framework\TestCase;

final class LegacyContentProjectGenerationCompatibilityTest extends TestCase
{
    public function test_full_workflow_uses_clean_restart_context(): void
    {
        $source = (string) file_get_contents(ProjectRoot::addonsPath().'/content-projects/src/Services/SeoProjectWorkflowRunService.php');

        self::assertStringContainsString('cleanRestart:', $source);
        self::assertStringContainsString('$fromStep === null', $source);
    }

    public function test_clean_restart_rewrite_does_not_require_existing_outline_before_workflow_runs(): void
    {
        $source = (string) file_get_contents(ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskTestInputResolver.php');
        $start = strpos($source, 'private function resolveExistingArticleRewriteForCleanRestart');
        self::assertNotFalse($start);
        $end = strpos($source, 'private function stampProjectTaskOrigin', (int) $start);
        self::assertNotFalse($end);
        $chunk = substr($source, (int) $start, (int) $end - (int) $start);

        self::assertStringNotContainsString('applyOutlineFromArticle', $chunk);
        self::assertStringContainsString("'rerun_scope'] = 'full'", $chunk);
        self::assertStringContainsString("'force_ai_regenerate'] = 'true'", $chunk);
        self::assertStringContainsString("'article_writing_raw_input'", $chunk);
    }

    public function test_create_clean_restart_forces_keyword_and_skips_title_lookup(): void
    {
        $source = (string) file_get_contents(ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskTestInputResolver.php');
        self::assertStringContainsString('function resolveCreateArticleForCleanRestart', $source);
        $start = strpos($source, 'private function resolveCreateArticleForCleanRestart');
        self::assertNotFalse($start);
        $end = strpos($source, 'private function resolveExistingArticleRewrite(', (int) $start);
        self::assertNotFalse($end);
        $chunk = substr($source, (int) $start, (int) $end - (int) $start);
        self::assertStringContainsString("'rerun_scope'] = 'full'", $chunk);
        self::assertStringContainsString("'force_ai_regenerate'] = 'true'", $chunk);
        self::assertStringContainsString('withArticle($article, true, \'id\')', $chunk);
        self::assertStringContainsString('focus_keyword', $chunk);
        self::assertStringNotContainsString('findArticleByTitle', $chunk);

        $runner = (string) file_get_contents(ProjectRoot::addonsPath().'/ai-prompt/src/Services/TaskWorkflowTestRunner.php');
        self::assertStringContainsString("\$rerunScope === 'full'", $runner);
    }

    public function test_repair_command_and_hydrator_are_registered(): void
    {
        self::assertTrue(class_exists(RepairLegacyContentProjectGenerationCommand::class));
        self::assertTrue(class_exists(LegacyContentProjectItemHydrator::class));

        $provider = (string) file_get_contents(LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'));
        self::assertStringContainsString('RepairLegacyContentProjectGenerationCommand::class', $provider);

        $command = new RepairLegacyContentProjectGenerationCommand;
        self::assertStringContainsString('seo:content-project:repair-legacy', (string) $command->getName());
    }

    public function test_hydrator_preserves_business_publish_fields_by_not_referencing_them(): void
    {
        $source = (string) file_get_contents(ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/LegacyContentProjectItemHydrator.php');

        self::assertStringNotContainsString('wp_post_id', $source);
        self::assertStringNotContainsString('publish_published_at', $source);
        self::assertStringNotContainsString('scheduled_publish_at', $source);
        self::assertStringContainsString('dry_run', $source);
    }
}
