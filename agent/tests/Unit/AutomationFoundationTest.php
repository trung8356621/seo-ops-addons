<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Foundation\PingAction;
use Omnichannel\Addons\Agent\Automation\Contracts\ActionExecutionLoggerContract;
use Omnichannel\Addons\Agent\Automation\Contracts\AutomationEventDispatcher;
use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Enums\PublishIntent;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Agent\Automation\Registry\ActionCatalogBootstrap;
use Omnichannel\Addons\Agent\Automation\Registry\ActionRegistry;
use Omnichannel\Addons\Agent\Automation\Runtime\ActionRunner;
use Omnichannel\Addons\Agent\Automation\Support\CanonicalIds;
use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class AutomationFoundationTest extends TestCase
{
    private function registry(): ActionRegistry
    {
        $container = new Container;
        $registry = new ActionRegistry($container);
        (new ActionCatalogBootstrap)->register($registry);
        $registry->registerHandler(PingAction::class);

        return $registry;
    }

    private function runner(ActionRegistry $registry): ActionRunner
    {
        $logger = new class implements ActionExecutionLoggerContract
        {
            public function start(ActionContext $context, string $actionKey, ?string $entityType, ?int $entityId, array $input): void {}

            public function finish(string $executionId, ActionResult $result): void {}
        };

        $events = new class implements AutomationEventDispatcher
        {
            /** @var list<EventEnvelope> */
            public array $dispatched = [];

            public function dispatch(EventEnvelope $event): void
            {
                $this->dispatched[] = $event;
            }
        };

        return new ActionRunner($registry, $logger, $events);
    }

    public function test_catalog_registers_unique_keys(): void
    {
        $registry = $this->registry();
        $keys = array_keys($registry->definitions());

        self::assertSame(count($keys), count(array_unique($keys)));
        self::assertTrue($registry->has('article.content.update'));
        self::assertTrue($registry->has('wordpress.article.sync_outbound'));
        self::assertTrue($registry->has('automation.ping'));
        self::assertFalse($registry->has('wordpress.article.update'));
    }

    public function test_duplicate_definition_key_fails(): void
    {
        $registry = $this->registry();

        $this->expectException(AutomationException::class);
        $registry->registerDefinition(new ActionDefinition(
            key: 'article.create',
            name: 'dup',
            description: 'dup',
            module: 'article',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Low,
            selectability: ActionSelectability::Selectable,
        ));
    }

    public function test_handlers_implement_business_action(): void
    {
        $registry = $this->registry();
        $handler = $registry->get('automation.ping');

        self::assertInstanceOf(BusinessAction::class, $handler);
        self::assertSame('automation.ping', $handler::definition()->key);
    }

    public function test_validate_input_schema(): void
    {
        $registry = $this->registry();

        self::assertNotEmpty($registry->validate('article.content.update', []));
        self::assertSame([], $registry->validate('article.content.update', [
            'article_id' => 12,
            'content' => '<p>Hi</p>',
        ]));
    }

    public function test_registry_does_not_resolve_class_from_user_input(): void
    {
        $registry = $this->registry();

        $this->expectException(AutomationException::class);
        $registry->get(PingAction::class);
    }

    public function test_canonical_ids_normalize_aliases(): void
    {
        $normalized = CanonicalIds::normalizeContextAttributes([
            'website_id' => 9,
            'domain_id' => 99,
            'actor_id' => 1,
        ]);

        self::assertSame(9, $normalized['site_id']);
        self::assertArrayNotHasKey('website_id', $normalized);
        self::assertArrayNotHasKey('domain_id', $normalized);

        $context = ActionContext::fromArray([
            'origin' => 'test',
            'website_id' => 5,
        ]);
        self::assertSame(5, $context->siteId);
    }

    public function test_sync_outbound_not_selectable_for_workflow(): void
    {
        $registry = $this->registry();
        $definition = $registry->definition('wordpress.article.sync_outbound');

        self::assertSame(ActionSelectability::LegacyNotSelectable, $definition->selectability);
        self::assertTrue($definition->impliesPublishStatus);
        self::assertNotContains('wordpress.article.sync_outbound', $registry->selectableKeys());
        self::assertNotContains('wordpress.article.publish', $registry->selectableKeys());
    }

    public function test_runner_blocks_legacy_action_from_workflow_origin(): void
    {
        $registry = $this->registry();
        $runner = $this->runner($registry);

        $this->expectException(AutomationException::class);
        $runner->run(
            'wordpress.article.sync_outbound',
            ActionContext::fromArray(['origin' => 'workflow.rule_engine']),
            ['article_id' => 1],
        );
    }

    public function test_publish_requires_publish_intent(): void
    {
        $registry = $this->registry();
        $runner = $this->runner($registry);

        $this->expectException(AutomationException::class);
        $runner->run(
            'wordpress.article.publish',
            ActionContext::fromArray(['origin' => 'editor.manual']),
            ['article_id' => 1],
        );
    }

    public function test_publish_rejects_remote_update_intent(): void
    {
        $registry = $this->registry();
        $runner = $this->runner($registry);

        $this->expectException(AutomationException::class);
        $runner->run(
            'wordpress.article.publish',
            ActionContext::fromArray([
                'origin' => 'editor.manual',
                'publish_intent' => PublishIntent::RemoteUpdate->value,
            ]),
            ['article_id' => 1],
        );
    }

    public function test_ping_action_runs(): void
    {
        $registry = $this->registry();
        $runner = $this->runner($registry);

        $result = $runner->run(
            'automation.ping',
            ActionContext::fromArray(['origin' => 'foundation.test']),
            ['message' => 'hello'],
        );

        self::assertTrue($result->success);
        self::assertTrue($result->output['pong']);
        self::assertSame('hello', $result->output['message']);
    }

    public function test_blocker_action_without_handler(): void
    {
        $registry = $this->registry();
        $runner = $this->runner($registry);

        $result = $runner->run(
            'article.review.request',
            ActionContext::fromArray(['origin' => 'foundation.test']),
            ['article_id' => 1],
        );

        self::assertFalse($result->success);
        self::assertSame('handler_missing', $result->error['code'] ?? null);
    }

    public function test_sensitive_redactor_hides_tokens(): void
    {
        $redactor = new SensitivePayloadRedactor;
        $out = $redactor->redact([
            'title' => 'ok',
            'api_token' => 'secret-value',
            'nested' => ['password' => 'x'],
        ]);

        self::assertSame('ok', $out['title']);
        self::assertSame('[redacted]', $out['api_token']);
        self::assertSame('[redacted]', $out['nested']['password']);
    }

    public function test_publish_intent_allows_article_publish_action(): void
    {
        self::assertTrue(PublishIntent::ManualPublish->allowsArticlePublishAction());
        self::assertTrue(PublishIntent::ScheduledPublish->allowsArticlePublishAction());
        self::assertTrue(PublishIntent::Republish->allowsArticlePublishAction());
        self::assertFalse(PublishIntent::RemoteUpdate->allowsArticlePublishAction());
    }
}
