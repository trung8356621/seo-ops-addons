<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\DispatchEventHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventBootstrap;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationRuleService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationLoopGuard;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSnapshotSanitizer;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Business Hook hardening â€” pure logic, no DB required.
 */
final class BusinessHookHardeningTest extends TestCase
{
    public function test_snapshot_sanitizer_redacts_sensitive_key_fragments(): void
    {
        $sanitizer = new AutomationSnapshotSanitizer(new SensitivePayloadRedactor());

        $result = $sanitizer->sanitize([
            'password' => 'secret123',
            'PasswordHash' => 'x',
            'user_token' => 'tok',
            'API_KEY' => 'k1',
            'Authorization' => 'Bearer abc',
            'nested' => [
                'client_secret' => 's',
                'session_cookie' => 'c',
                'my_credential' => 'cred',
            ],
        ]);

        self::assertSame('[redacted]', $result['password']);
        self::assertSame('[redacted]', $result['PasswordHash']);
        self::assertSame('[redacted]', $result['user_token']);
        self::assertSame('[redacted]', $result['API_KEY']);
        self::assertSame('[redacted]', $result['Authorization']);
        self::assertSame('[redacted]', $result['nested']['client_secret']);
        self::assertSame('[redacted]', $result['nested']['session_cookie']);
        self::assertSame('[redacted]', $result['nested']['my_credential']);
    }

    public function test_sanitize_message_redacts_password_assignment(): void
    {
        $sanitizer = new AutomationSnapshotSanitizer(new SensitivePayloadRedactor());

        $cleaned = $sanitizer->sanitizeMessage('Connection failed: password=foo and retry');

        self::assertIsString($cleaned);
        self::assertStringNotContainsString('password=foo', $cleaned);
        self::assertStringContainsString('[redacted]', $cleaned);
    }

    public function test_loop_guard_max_depth_and_event_rule_loop(): void
    {
        $guard = new AutomationLoopGuard();

        try {
            $guard->assertAllowed(['automation_depth' => AutomationLoopGuard::MAX_DEPTH], 'article.completed');
            self::fail('Expected AutomationException for max depth');
        } catch (AutomationException $e) {
            self::assertSame(BusinessHookErrorCode::MaxDepthExceeded->value, $e->errorCode);
        }

        try {
            $guard->assertAllowed([
                'automation_depth' => 1,
                'automation_chain' => ['article.completed#7'],
            ], 'article.completed', 7);
            self::fail('Expected AutomationException for loop');
        } catch (AutomationException $e) {
            self::assertSame(BusinessHookErrorCode::LoopDetected->value, $e->errorCode);
        }
    }

    public function test_loop_guard_child_context_increases_depth(): void
    {
        $guard = new AutomationLoopGuard();
        $child = $guard->childContext(['automation_depth' => 2, 'automation_chain' => []], 'article.completed', 5);

        self::assertSame(3, $child['automation_depth']);
        self::assertContains('article.completed#5', $child['automation_chain']);
    }

    public function test_should_bump_version_policy_via_reflection(): void
    {
        self::assertTrue(method_exists(AutomationRuleService::class, 'enable'));

        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/BusinessHook/Services/AutomationRuleService.php',
        );
        self::assertStringContainsString('khÃ´ng tÄƒng version', $source);

        $service = app(AutomationRuleService::class);
        $method = new ReflectionMethod(AutomationRuleService::class, 'shouldBumpVersion');
        $method->setAccessible(true);

        $rule = new AutomationRule([
            'event_name' => BusinessEventName::ArticleCompleted->value,
            'conditions' => null,
            'priority' => 100,
            'run_mode' => 'queued',
            'stop_on_failure' => true,
            'settings' => null,
            'version' => 1,
        ]);

        self::assertFalse($method->invoke($service, $rule, ['is_enabled' => true], null));
        self::assertTrue($method->invoke($service, $rule, ['event_name' => 'article.published'], null));
        self::assertTrue($method->invoke($service, $rule, [], []));
    }

    public function test_sensitive_payload_redactor_includes_cookie_fragment(): void
    {
        $redactor = new SensitivePayloadRedactor();
        $result = $redactor->redact([
            'session_cookie' => 'abc123',
            'safe_field' => 'visible',
        ]);

        self::assertSame('[redacted]', $result['session_cookie']);
        self::assertSame('visible', $result['safe_field']);
    }

    public function test_dispatch_event_hook_action_returns_dispatch_events_with_event_name(): void
    {
        $registry = new BusinessEventRegistry();
        (new BusinessEventBootstrap)->register($registry);

        $action = new DispatchEventHookAction($registry);
        $context = new AutomationActionContext(
            businessEvent: new BusinessEvent([
                'event_name' => BusinessEventName::ArticleCompleted->value,
                'payload' => ['article_id' => 1],
            ]),
            rule: new AutomationRule(['code' => 'test-dispatch']),
            execution: new AutomationExecution(['id' => 1, 'idempotency_key' => 'k']),
            subject: null,
            subjectData: [],
            siteId: 1,
            projectId: null,
            actorId: null,
            correlationId: null,
            automationDepth: 0,
        );

        $result = $action->handle($context, [], [
            'event_name' => BusinessEventName::ContentProjectTaskCompleted->value,
        ]);

        self::assertTrue($result->success);
        self::assertCount(1, $result->dispatchEvents);
        self::assertSame(
            BusinessEventName::ContentProjectTaskCompleted->value,
            $result->dispatchEvents[0]['event_name'],
        );
    }
}
