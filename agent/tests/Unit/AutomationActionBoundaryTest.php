<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\Registry\ActionHandlerRegistrar;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Architecture: Article/Project/Seo/Keyword actions must not depend on WP outbound hubs.
 */
final class AutomationActionBoundaryTest extends TestCase
{
    /** @var list<string> */
    private const FORBIDDEN = [
        'WordPressArticleSyncService',
        'ArticleEditorSyncOrchestrator',
        'ArticleWpSyncQueueService',
        'WordPressCommentReviewService',
        'ScheduledArticlePublishRunner',
        'publishForArticle',
        'syncForArticle',
        'SyncArticleToWordPressFromQueueJob',
        'Filament\\Resources\\',
        'ArticleResource',
        'KeywordResource',
    ];

    /** @var list<string> */
    private const SCOPES = [
        'Article',
        'Project',
        'Seo',
        'Keyword',
    ];

    public function test_local_module_actions_do_not_reference_wordpress_outbound(): void
    {
        $base = ProjectRoot::addonsPath().'/agent/src'.DIRECTORY_SEPARATOR.'Automation'.DIRECTORY_SEPARATOR.'Actions';

        foreach (self::SCOPES as $scope) {
            $dir = $base.DIRECTORY_SEPARATOR.$scope;
            self::assertDirectoryExists($dir, "Missing actions dir [{$scope}]");

            foreach ($this->phpFiles($dir) as $file) {
                $contents = (string) file_get_contents($file);
                foreach (self::FORBIDDEN as $needle) {
                    self::assertStringNotContainsString(
                        $needle,
                        $contents,
                        basename($file)." must not reference [{$needle}]",
                    );
                }
            }
        }
    }

    public function test_group2_wired_callers_and_bridges_avoid_wordpress_outbound(): void
    {
        $paths = [
            ProjectRoot::addonsPath().'/content-projects/src/Services/CreateArticlesFromTaskService.php',
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptTestPublishService.php',
            ProjectRoot::addonsPath().'/agent/src/Automation/Migration/ProjectArticleCreateCallerBridge.php',
            ProjectRoot::addonsPath().'/agent/src/Automation/Migration/ProjectArticleContentCallerBridge.php',
            ProjectRoot::addonsPath().'/agent/src/Automation/Migration/ProjectArticleSeoMetaCallerBridge.php',
        ];

        foreach ($paths as $path) {
            self::assertFileExists($path);
            $contents = (string) file_get_contents($path);
            foreach (self::FORBIDDEN as $needle) {
                if (in_array($needle, ['Filament\\Resources\\', 'ArticleResource', 'KeywordResource'], true)) {
                    continue;
                }
                self::assertStringNotContainsString(
                    $needle,
                    $contents,
                    basename($path)." must not reference [{$needle}]",
                );
            }
        }
    }

    public function test_registered_handlers_implement_business_action_and_unique_keys(): void
    {
        $keys = [];
        foreach (ActionHandlerRegistrar::handlers() as $class) {
            self::assertTrue(
                is_subclass_of($class, \Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction::class),
                "{$class} must implement BusinessAction",
            );
            $key = $class::definition()->key;
            self::assertArrayNotHasKey($key, $keys, "Duplicate handler key [{$key}]");
            $keys[$key] = $class;
        }

        self::assertArrayNotHasKey('article.review.request', $keys);
        self::assertArrayNotHasKey('wordpress.article.publish', $keys);
        self::assertArrayNotHasKey('wordpress.article.sync_outbound', $keys);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
