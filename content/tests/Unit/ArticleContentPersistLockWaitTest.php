<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Article\UpdateArticleContentAction;
use Omnichannel\Addons\Content\Services\ArticleEditorPersistService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: article.content.update giữ TX ngắn trên articles row + retry lock-wait.
 */
final class ArticleContentPersistLockWaitTest extends TestCase
{
    public function test_persist_service_exposes_short_row_write_and_side_effects(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleEditorPersistService::class))->getFileName(),
        );

        self::assertStringContainsString('function writeArticleRow', $source);
        self::assertStringContainsString('function runAfterPersistSideEffects', $source);
        self::assertStringContainsString('function buildPersistResult', $source);

        $write = $this->methodSource(
            new ReflectionMethod(ArticleEditorPersistService::class, 'writeArticleRow'),
        );
        self::assertStringContainsString("'body' => \$html", $write);
        self::assertStringNotContainsString('postImages->syncFromHtml', $write);
        self::assertStringNotContainsString('revisions->captureAfterSave', $write);
        self::assertStringNotContainsString('keywordLinks->reconcileForArticle', $write);

        $side = $this->methodSource(
            new ReflectionMethod(ArticleEditorPersistService::class, 'runAfterPersistSideEffects'),
        );
        self::assertStringContainsString('postImages->syncFromHtml', $side);
        self::assertStringContainsString('revisions->captureAfterSave', $side);
        self::assertStringContainsString('keywordLinks->reconcileForArticle', $side);

        $scheduleSync = $this->methodSource(
            new ReflectionMethod(ArticleEditorPersistService::class, 'syncContentProjectScheduledPublish'),
        );
        self::assertStringContainsString('STATUS_WRITING', $scheduleSync);
        self::assertStringContainsString('STATUS_PROCESSING', $scheduleSync);
        self::assertStringContainsString('catch (\\RuntimeException)', $scheduleSync);
    }

    public function test_update_action_retries_lock_wait_outside_side_effects(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(UpdateArticleContentAction::class))->getFileName(),
        );

        self::assertStringContainsString('persistUnderShortRowLock', $source);
        self::assertStringContainsString('isLockWaitTimeout', $source);
        self::assertStringContainsString('friendlyPersistError', $source);
        self::assertStringContainsString('writeArticleRow', $source);
        self::assertStringContainsString('runAfterPersistSideEffects', $source);
        self::assertStringContainsString('Lock wait timeout', $source);
        self::assertStringContainsString('1205', $source);

        $method = $this->methodSource(
            new ReflectionMethod(UpdateArticleContentAction::class, 'persistUnderShortRowLock'),
        );
        self::assertStringContainsString('writeArticleRow', $method);
        self::assertStringContainsString('runAfterPersistSideEffects', $method);
        self::assertTrue(
            strpos($method, 'writeArticleRow') < strpos($method, 'runAfterPersistSideEffects'),
            'side-effects must run after short row TX',
        );
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
