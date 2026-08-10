<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\WordPress\Services\SideEffect\ManualWordPressContext;
use Omnichannel\Addons\WordPress\Services\SideEffect\UnauthorizedWordPressSideEffectException;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressGateway;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressSideEffectGuard;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressSideEffectLedger;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WordPressSideEffectFirewallTest extends TestCase
{
    public function test_gateway_blocks_null_context(): void
    {
        $this->expectException(UnauthorizedWordPressSideEffectException::class);
        $this->expectExceptionMessage(UnauthorizedWordPressSideEffectException::ORIGIN_MISSING);

        $gateway = app(WordPressGateway::class);
        $gateway->postJson(
            null,
            'article.editor_sync',
            'https://example.test/wp-json/omi-seo-ai/v1/posts/1/editor-sync',
            'token',
            ['status' => 'publish'],
            5,
            99,
            1,
        );
    }

    public function test_gateway_allows_manual_context_past_null_check(): void
    {
        Http::fake();

        $ctx = new ManualWordPressContext(
            userId: 7,
            requestId: 'req-firewall-1',
            articleId: 99,
            siteId: 1,
            reason: 'unit_test',
            correlationId: 'corr-1',
        );

        $gateway = app(WordPressGateway::class);
        // Manual context is allowed by guard; HTTP may still fail on fake URL â€” must not be ORIGIN_INVALID.
        try {
            $gateway->postJson(
                $ctx,
                'article.editor_sync',
                'https://example.test/wp-json/omi-seo-ai/v1/posts/1/editor-sync',
                'token',
                ['status' => 'publish'],
                5,
                99,
                1,
            );
        } catch (UnauthorizedWordPressSideEffectException $e) {
            self::fail('Manual context must not be blocked as invalid origin: '.$e->getMessage());
        } catch (\Throwable) {
            // Network/HTTP fake errors OK after guard passed.
        }

        self::assertTrue(true);
    }

    public function test_guard_rejects_non_manual_non_automation_origin_via_gateway_null(): void
    {
        $guard = app(WordPressSideEffectGuard::class);
        $this->expectException(UnauthorizedWordPressSideEffectException::class);
        $guard->assertAllowed(null, 'article.create_post', ['article_id' => 1]);
    }

    public function test_article_sync_service_requires_context_parameter(): void
    {
        $method = new \ReflectionMethod(
            \Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService::class,
            'syncForArticle',
        );
        $params = $method->getParameters();
        self::assertGreaterThanOrEqual(2, count($params));
        self::assertSame('sideEffect', $params[1]->getName());
    }

    public function test_sync_service_has_no_raw_http_post(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressArticleSyncService.php',
        );
        self::assertStringNotContainsString('Http::', $source);
        self::assertStringContainsString('WordPressGateway', $source);
        self::assertStringContainsString('WordPressExecutionContext', $source);
    }

    public function test_queue_job_is_deprecated_shell(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Jobs/SyncArticleToWordPressFromQueueJob.php',
        );
        self::assertStringContainsString('DEPRECATED', $source);
        self::assertStringNotContainsString('syncFromEditorBundle', $source);
    }

    public function test_scheduled_runner_does_not_call_wordpress_sync_service(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/publishing/src/Services/ScheduledArticlePublishRunner.php',
        );
        self::assertStringNotContainsString('WordPressArticleSyncService', $source);
        self::assertStringNotContainsString('publishScheduledArticle', $source);
        self::assertStringContainsString('ArticlePublishRequested', $source);
    }

    public function test_ledger_service_exists(): void
    {
        self::assertTrue(class_exists(WordPressSideEffectLedger::class));
        self::assertTrue(class_exists(\Omnichannel\Addons\WordPress\Models\WordPressSideEffectAttempt::class));
    }
}
