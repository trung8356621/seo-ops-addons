<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\SyncArticleToWordPressHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Events\BridgingAutomationEventDispatcher;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionBootstrap;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventBootstrap;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Seed\AutomationDefaultRulesSeeder;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationConditionEngine;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationInputMapper;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationLoopGuard;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Container\Container;
use ReflectionClass;
use Tests\TestCase;

/**
 * Business Hook engine â€” pure logic, no DB required.
 */
final class BusinessHookEngineTest extends TestCase
{
    private function eventRegistry(): BusinessEventRegistry
    {
        $registry = new BusinessEventRegistry;
        (new BusinessEventBootstrap)->register($registry);

        return $registry;
    }

    private function actionRegistry(?Container $container = null): AutomationActionRegistry
    {
        $registry = new AutomationActionRegistry($container ?? $this->app);
        (new AutomationActionBootstrap)->register($registry);

        return $registry;
    }

    public function test_business_event_registry_has_core_events_and_rejects_unknown(): void
    {
        $registry = $this->eventRegistry();

        self::assertTrue($registry->has(BusinessEventName::ArticleCompleted->value));
        self::assertTrue($registry->has(BusinessEventName::ContentProjectTaskCompleted->value));
        self::assertGreaterThanOrEqual(10, count($registry->all()));

        try {
            $registry->get('not.a.real.event');
            self::fail('Expected AutomationException');
        } catch (AutomationException $e) {
            self::assertSame(BusinessHookErrorCode::EventNotRegistered->value, $e->errorCode);
        }
    }

    public function test_automation_action_registry_has_wordpress_sync_and_rejects_unknown(): void
    {
        $registry = $this->actionRegistry();

        self::assertTrue($registry->has(AutomationActionCode::WordpressArticleSync->value));
        self::assertSame('wordpress.article.sync', AutomationActionCode::WordpressArticleSync->value);

        try {
            $registry->get('evil.action');
            self::fail('Expected AutomationException');
        } catch (AutomationException $e) {
            self::assertSame(BusinessHookErrorCode::ActionNotRegistered->value, $e->errorCode);
        }
    }

    public function test_input_mapper_resolves_payload_and_subject_and_rejects_evil_paths(): void
    {
        $mapper = new AutomationInputMapper;
        $sources = [
            'event' => ['site_id' => 5],
            'payload' => ['title' => 'Hello SEO'],
            'context' => [],
            'subject' => ['id' => 42],
            'previous' => [],
        ];

        self::assertSame('Hello SEO', $mapper->map(['title' => '{{ payload.title }}'], $sources)['title']);
        self::assertSame(42, $mapper->map(['id' => '{{ subject.id }}'], $sources)['id']);

        try {
            $mapper->resolvePath('php.eval', $sources);
            self::fail('Expected AutomationException for evil path');
        } catch (AutomationException $e) {
            self::assertSame(BusinessHookErrorCode::InvalidInputMapping->value, $e->errorCode);
        }
    }

    public function test_condition_engine_all_any_equals_in_exists(): void
    {
        $engine = new AutomationConditionEngine(new AutomationInputMapper);
        $sources = [
            'event' => ['site_id' => 10, 'event_name' => 'article.completed'],
            'payload' => ['status' => 'completed', 'tags' => ['a', 'b']],
            'context' => [],
            'subject' => ['id' => 1],
            'previous' => [],
        ];

        self::assertTrue($engine->matches([
            'all' => [
                ['field' => 'event.site_id', 'operator' => 'exists'],
                ['field' => 'payload.status', 'operator' => 'equals', 'value' => 'completed'],
            ],
        ], $sources));

        self::assertTrue($engine->matches([
            'any' => [
                ['field' => 'payload.status', 'operator' => 'equals', 'value' => 'draft'],
                ['field' => 'payload.status', 'operator' => 'in', 'value' => ['completed', 'published']],
            ],
        ], $sources));

        self::assertFalse($engine->matches([
            'field' => 'subject.id',
            'operator' => 'equals',
            'value' => 99,
        ], $sources));

        self::assertTrue($engine->matches([
            'field' => 'event.site_id',
            'operator' => 'exists',
        ], $sources));
    }

    public function test_loop_guard_max_depth_throws(): void
    {
        $guard = new AutomationLoopGuard;
        $context = ['automation_depth' => AutomationLoopGuard::MAX_DEPTH];

        try {
            $guard->assertAllowed($context, 'article.completed');
            self::fail('Expected AutomationException');
        } catch (AutomationException $e) {
            self::assertSame(BusinessHookErrorCode::MaxDepthExceeded->value, $e->errorCode);
            self::assertSame('AUTOMATION_MAX_DEPTH_EXCEEDED', $e->errorCode);
        }
    }

    public function test_loop_guard_detects_event_rule_loop(): void
    {
        $guard = new AutomationLoopGuard;
        $signature = 'article.completed#7';
        $context = [
            'automation_depth' => 1,
            'automation_chain' => [$signature],
        ];

        try {
            $guard->assertAllowed($context, 'article.completed', 7);
            self::fail('Expected AutomationException');
        } catch (AutomationException $e) {
            self::assertSame(BusinessHookErrorCode::LoopDetected->value, $e->errorCode);
        }
    }

    public function test_idempotency_key_formula(): void
    {
        $eventUuid = '550e8400-e29b-41d4-a716-446655440000';
        $ruleId = 12;
        $version = 3;

        $expected = hash('sha256', $eventUuid.'|'.$ruleId.'|'.$version);
        $actual = hash('sha256', implode('|', [$eventUuid, (string) $ruleId, (string) $version]));

        self::assertSame($expected, $actual);
        self::assertSame(64, strlen($actual));
    }

    public function test_bridging_key_map_project_task_completed(): void
    {
        $reflection = new ReflectionClass(BridgingAutomationEventDispatcher::class);
        /** @var array<string, string> $keyMap */
        $keyMap = $reflection->getConstant('KEY_MAP');

        self::assertArrayHasKey('project.task_completed', $keyMap);
        self::assertSame(
            BusinessEventName::ContentProjectTaskCompleted->value,
            $keyMap['project.task_completed'],
        );
        self::assertSame('content_project.task.completed', $keyMap['project.task_completed']);
    }

    public function test_sync_article_hook_action_implements_handler(): void
    {
        self::assertTrue(class_exists(SyncArticleToWordPressHookAction::class));
        self::assertTrue(is_subclass_of(SyncArticleToWordPressHookAction::class, AutomationActionHandler::class));

        $registry = $this->actionRegistry();
        $definition = $registry->get(AutomationActionCode::WordpressArticleSync->value);
        self::assertSame(SyncArticleToWordPressHookAction::class, $definition->handlerClass);
    }

    public function test_default_rules_seeder_defines_expected_codes(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Seed/AutomationDefaultRulesSeeder.php',
        );

        self::assertStringContainsString("'sync-article-to-wordpress'", $source);
        self::assertStringContainsString("'notify-workflow-failure'", $source);
        self::assertStringContainsString("'dispatch-publish-request'", $source);
        self::assertStringContainsString('article > wordpress', $source);
        self::assertStringContainsString('article.publish.request > wordpress', $source);

        foreach (['sync-article-to-wordpress', 'dispatch-publish-request'] as $code) {
            self::assertMatchesRegularExpression(
                "/code:\\s*'{$code}'[\\s\\S]{0,800}'is_enabled'\\s*=>\\s*true/",
                $source,
                "Expected {$code} enabled in seeder.",
            );
        }

        self::assertTrue(class_exists(AutomationDefaultRulesSeeder::class));
    }
}
