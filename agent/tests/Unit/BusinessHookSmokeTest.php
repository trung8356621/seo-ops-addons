<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\SyncArticleToWordPressHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\BusinessEventDispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Queue smoke scenarios — requires omi_seo_ai migrated + seeded rules.
 * Skip automatically when business_events table missing.
 */
final class BusinessHookSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            BusinessEvent::query()->limit(1)->get();
        } catch (\Throwable) {
            $this->markTestSkipped('business_events table not available — run SEO migrations first.');
        }
    }

    public function test_disabled_wordpress_rule_does_not_queue_sync_on_article_completed(): void
    {
        Queue::fake();

        $rule = AutomationRule::query()->where('code', 'sync-article-to-wordpress')->first();
        if (! $rule instanceof AutomationRule) {
            $this->markTestSkipped('Seed rule sync-article-to-wordpress missing — run automation:seed-rules.');
        }

        $this->assertFalse((bool) $rule->is_enabled);

        $uuid = 'smoke-disabled-'.uniqid('', true);
        app(BusinessEventDispatcher::class)->dispatch(
            BusinessEventName::ArticleCompleted->value,
            payload: [
                'article_id' => 999001,
                'site_id' => 1,
                'status' => 'completed',
            ],
            context: ['site_id' => 1],
            eventUuid: $uuid,
        );

        Queue::assertNotPushed(ExecuteAutomationRuleJob::class);

        $event = BusinessEvent::query()->where('event_uuid', $uuid)->first();
        $this->assertNotNull($event);
        $this->assertSame(0, AutomationExecution::query()->where('business_event_id', $event->id)->count());
    }

    public function test_enabled_rule_creates_execution_and_queues_job(): void
    {
        Queue::fake();

        $rule = AutomationRule::query()->where('code', 'sync-article-to-wordpress')->first();
        if (! $rule instanceof AutomationRule) {
            $this->markTestSkipped('Seed rule missing.');
        }

        $originalEnabled = (bool) $rule->is_enabled;
        $rule->forceFill(['is_enabled' => true])->save();

        try {
            $uuid = 'smoke-enabled-'.uniqid('', true);
            $event = app(BusinessEventDispatcher::class)->dispatch(
                BusinessEventName::ArticleCompleted->value,
                payload: [
                    'article_id' => 1,
                    'site_id' => 1,
                    'status' => 'completed',
                ],
                context: ['site_id' => 1],
                eventUuid: $uuid,
            );

            $execution = AutomationExecution::query()
                ->where('business_event_id', $event->id)
                ->where('automation_rule_id', $rule->id)
                ->first();

            $this->assertNotNull($execution);
            $this->assertSame('pending', $execution->status);
            Queue::assertPushed(ExecuteAutomationRuleJob::class, function (ExecuteAutomationRuleJob $job) use ($execution): bool {
                return $job->automationExecutionId === (int) $execution->id
                    && $job->queue === \Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName::Critical->value;
            });
        } finally {
            $rule->forceFill(['is_enabled' => $originalEnabled])->save();
        }
    }

    public function test_duplicate_event_uuid_creates_single_business_event(): void
    {
        $uuid = 'smoke-dup-'.uniqid('', true);
        $first = app(BusinessEventDispatcher::class)->dispatch(
            BusinessEventName::ArticleCompleted->value,
            payload: ['article_id' => 2, 'site_id' => 1],
            eventUuid: $uuid,
        );
        $second = app(BusinessEventDispatcher::class)->dispatch(
            BusinessEventName::ArticleCompleted->value,
            payload: ['article_id' => 2, 'site_id' => 1],
            eventUuid: $uuid,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BusinessEvent::query()->where('event_uuid', $uuid)->count());
    }

    public function test_wordpress_hook_action_class_is_wired(): void
    {
        $this->assertTrue(is_a(SyncArticleToWordPressHookAction::class, \Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler::class, true));
        $this->assertSame('wordpress.article.sync', AutomationActionCode::WordpressArticleSync->value);
    }
}
