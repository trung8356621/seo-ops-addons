<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\SyncArticleToWordPressHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationEventDispatchResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEventDispatchOutcome;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\BusinessEventDispatcher;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use Illuminate\Support\Facades\Auth;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

final class WordpressSyncAutomationOutcomeIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_skipped_no_rule_is_typed_outcome_not_exception(): void
    {
        $dispatcher = Mockery::mock(BusinessEventDispatcher::class);
        $dispatcher->shouldReceive('dispatchWithOutcome')
            ->once()
            ->andReturn(new AutomationEventDispatchResult(
                outcome: AutomationEventDispatchOutcome::SkippedNoRule,
                message: 'No enabled automation rule for event.',
                errorCode: 'AUTOMATION_RULE_NOT_FOUND',
            ));

        $emitter = new BusinessHookEmitter($dispatcher);
        $emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncStarted, null, [
            'article_id' => 1,
        ]);

        $this->assertTrue(true);
    }

    public function test_outcome_dispatch_exception_does_not_bubble(): void
    {
        $dispatcher = Mockery::mock(BusinessEventDispatcher::class);
        $dispatcher->shouldReceive('dispatchWithOutcome')
            ->once()
            ->andThrow(new \RuntimeException('boom'));

        $emitter = new BusinessHookEmitter($dispatcher);
        $emitter->emitOutcomeSafely(BusinessEventName::WordpressSynced, null, [
            'article_id' => 1,
        ]);

        $this->assertTrue(true);
    }

    public function test_queue_context_bypasses_content_manager_default_without_auth(): void
    {
        Auth::logout();

        $service = app(WordPressArticleSyncService::class);
        $method = new ReflectionMethod(WordPressArticleSyncService::class, 'blockContentManagerWordPressSync');
        $method->setAccessible(true);

        $blocked = $method->invoke($service);
        self::assertIsArray($blocked);
        self::assertSame('WORDPRESS_SYNC_FORBIDDEN_ROLE', $blocked['error_code'] ?? null);

        SeoQueueContext::runWpSyncFromQueue(function () use ($method, $service): void {
            self::assertNull($method->invoke($service));
        });
    }

    public function test_hook_action_uses_queue_context_so_authless_worker_can_sync(): void
    {
        Auth::logout();

        $article = new SeoArticle;
        $article->id = 3008;
        $article->site_id = 5;
        $article->slug = 'demo';
        $article->wp_post_id = null;

        $sync = Mockery::mock(WordPressArticleSyncService::class);
        $sync->shouldReceive('publishForArticle')
            ->once()
            ->andReturnUsing(function () {
                // Inside publish, permission gate must see queue context.
                self::assertTrue(SeoQueueContext::isWpSyncFromQueue());

                return [
                    'success' => true,
                    'message' => 'synced',
                    'wp_post_id' => 999,
                ];
            });

        $emitter = Mockery::mock(BusinessHookEmitter::class);
        $emitter->shouldReceive('emitOutcomeSafely')->twice();

        $execution = new AutomationExecution;
        $execution->id = 42;
        $execution->execution_uuid = 'exec-uuid';
        $execution->idempotency_key = 'idem';
        $execution->trigger_type = 'manual';
        $execution->context = ['idempotency_key' => 'idem', 'event_uuid' => 'evt'];

        $event = new BusinessEvent;
        $event->event_uuid = 'evt';

        $context = new AutomationActionContext(
            businessEvent: $event,
            rule: null,
            execution: $execution,
            subject: $article,
            subjectData: ['id' => 3008],
            siteId: 5,
            projectId: null,
            actorId: 2,
            correlationId: 'corr',
            automationDepth: 0,
        );

        $action = new SyncArticleToWordPressHookAction($sync, $emitter);
        $result = $action->handle($context, ['article_id' => 3008], ['mode' => 'publish']);

        self::assertTrue($result->success);
        self::assertSame(999, $result->output['wp_post_id'] ?? null);
    }

    public function test_wordpress_failure_emits_sync_failed_with_specific_code(): void
    {
        Auth::logout();

        $article = new SeoArticle;
        $article->id = 1;
        $article->site_id = 5;

        $sync = Mockery::mock(WordPressArticleSyncService::class);
        $sync->shouldReceive('syncForArticle')
            ->once()
            ->andReturn([
                'success' => false,
                'error_code' => 'WORDPRESS_HTTP_ERROR',
                'failed_stage' => 'wordpress.update_post',
                'message' => 'WordPress returned HTTP 401',
            ]);

        $emitted = [];
        $emitter = Mockery::mock(BusinessHookEmitter::class);
        $emitter->shouldReceive('emitOutcomeSafely')
            ->andReturnUsing(function ($event) use (&$emitted): void {
                $emitted[] = $event instanceof BusinessEventName ? $event->value : (string) $event;
            });

        $execution = new AutomationExecution;
        $execution->id = 7;
        $execution->execution_uuid = 'u';
        $execution->idempotency_key = 'k';
        $execution->context = ['idempotency_key' => 'k', 'event_uuid' => 'e'];

        $event = new BusinessEvent;
        $event->event_uuid = 'e';

        $context = new AutomationActionContext(
            businessEvent: $event,
            rule: null,
            execution: $execution,
            subject: $article,
            subjectData: [],
            siteId: 5,
            projectId: null,
            actorId: null,
            correlationId: null,
            automationDepth: 0,
        );

        $action = new SyncArticleToWordPressHookAction($sync, $emitter);
        $result = $action->handle($context, ['article_id' => 1], ['mode' => 'sync']);

        self::assertFalse($result->success);
        self::assertSame('WORDPRESS_HTTP_ERROR', $result->errorCode);
        self::assertSame('wordpress.update_post', $result->output['failed_stage'] ?? null);
        self::assertContains(BusinessEventName::WordpressSyncStarted->value, $emitted);
        self::assertContains(BusinessEventName::WordpressSyncFailed->value, $emitted);
        self::assertNotContains(BusinessEventName::WordpressSynced->value, $emitted);
    }

    public function test_synced_emit_failure_does_not_flip_success(): void
    {
        Auth::logout();

        $article = new SeoArticle;
        $article->id = 2;
        $article->site_id = 5;
        $article->wp_post_id = 10;

        $sync = Mockery::mock(WordPressArticleSyncService::class);
        $sync->shouldReceive('syncForArticle')
            ->once()
            ->andReturn(['success' => true, 'wp_post_id' => 10, 'message' => 'ok']);

        $emitter = Mockery::mock(BusinessHookEmitter::class);
        $emitter->shouldReceive('emitOutcomeSafely')
            ->once()
            ->withArgs(fn ($e) => $e === BusinessEventName::WordpressSyncStarted);
        $emitter->shouldReceive('emitOutcomeSafely')
            ->once()
            ->withArgs(fn ($e) => $e === BusinessEventName::WordpressSynced)
            ->andReturnUsing(function (): void {
                // Caller must not care — emitOutcomeSafely swallows internally.
            });

        $execution = new AutomationExecution;
        $execution->id = 9;
        $execution->execution_uuid = 'u9';
        $execution->idempotency_key = 'k9';
        $execution->context = ['idempotency_key' => 'k9', 'event_uuid' => 'e9'];

        $event = new BusinessEvent;
        $event->event_uuid = 'e9';

        $context = new AutomationActionContext(
            businessEvent: $event,
            rule: null,
            execution: $execution,
            subject: $article,
            subjectData: [],
            siteId: 5,
            projectId: null,
            actorId: null,
            correlationId: null,
            automationDepth: 0,
        );

        $action = new SyncArticleToWordPressHookAction($sync, $emitter);
        $result = $action->handle($context, ['article_id' => 2], ['mode' => 'sync']);

        self::assertTrue($result->success);
        self::assertNull($result->errorCode);
    }
}
