<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Services\SyncArticleToWordPressPipeline;
use Omnichannel\Addons\WordPress\Services\WordPressManualSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Standalone articles without wp_post_id must create a WordPress post.
 * editor-sync (đòi wp_post_id) is update-only — never the first-publish path.
 */
final class StandaloneArticleWordPressCreateContractTest extends TestCase
{
    public function test_pipeline_default_sync_creates_when_unlinked(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SyncArticleToWordPressPipeline::class))->getFileName(),
        );

        self::assertStringContainsString('syncOrCreateStandalone', $source);
        self::assertStringContainsString('publishForArticle', $source);
        self::assertStringContainsString("'update_existing' => \$this->articleSync->syncForArticle", $source);

        $helper = $this->methodSource(
            new ReflectionMethod(SyncArticleToWordPressPipeline::class, 'syncOrCreateStandalone'),
        );
        self::assertStringContainsString('wordpressLink?->wp_post_id', $helper);
        self::assertStringContainsString('publishForArticle', $helper);
        self::assertStringContainsString('syncForArticle', $helper);
    }

    public function test_standalone_enqueue_and_retry_do_not_force_editor_sync_when_unlinked(): void
    {
        $enqueue = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'enqueueFromEditorBundle'),
        );
        self::assertStringContainsString('standalonePipelineMode', $enqueue);
        self::assertStringNotContainsString("publishImmediately ? 'publish' : 'sync'", $enqueue);

        $retry = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'resyncQueued'),
        );
        self::assertStringContainsString('standalonePipelineMode', $retry);
        self::assertStringNotContainsString("['mode' => 'sync']", $retry);

        $mode = $this->methodSource(
            new ReflectionMethod(WordPressManualSyncService::class, 'standalonePipelineMode'),
        );
        self::assertStringContainsString("'publish'", $mode);
        self::assertStringContainsString("'sync'", $mode);
        self::assertStringContainsString('wordpressLink?->wp_post_id', $mode);
    }

    public function test_rewrite_update_existing_still_never_creates(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(WordPressManualSyncService::class))->getFileName(),
        );
        $rewrite = $this->extractBetween(
            $source,
            'private function syncRewriteExistingFromEditorBundle',
            'private function mapPostPublishCommandResult',
        );

        self::assertStringContainsString('updatePublishedArticleOnly', $rewrite);
        self::assertStringNotContainsString('publishForArticle', $rewrite);
        self::assertStringContainsString("'create_post_called' => false", $rewrite);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $lines = file($file);
        self::assertIsArray($lines);

        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;

        return implode('', array_slice($lines, $start, $length));
    }

    private function extractBetween(string $source, string $start, string $end): string
    {
        $from = strpos($source, $start);
        $to = strpos($source, $end);
        self::assertIsInt($from);
        self::assertIsInt($to);

        return substr($source, $from, $to - $from);
    }
}
