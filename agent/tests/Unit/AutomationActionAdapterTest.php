<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Article\UpdateArticleContentAction;
use Omnichannel\Addons\Agent\Automation\Contracts\ActionExecutionLoggerContract;
use Omnichannel\Addons\Agent\Automation\Contracts\AutomationEventDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus;
use Omnichannel\Addons\Agent\Automation\Registry\ActionCatalogBootstrap;
use Omnichannel\Addons\Agent\Automation\Registry\ActionHandlerRegistrar;
use Omnichannel\Addons\Agent\Automation\Registry\ActionRegistry;
use Omnichannel\Addons\Agent\Automation\Runtime\ActionRunner;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class AutomationActionAdapterTest extends TestCase
{
    private function registry(): ActionRegistry
    {
        $container = new Container;
        $registry = new ActionRegistry($container);
        (new ActionCatalogBootstrap)->register($registry);

        // Register only definition-backed handlers that need no DB/container deps beyond class existence.
        // Full handler list needs Laravel container + DB — assert keys/handlers here.
        return $registry;
    }

    public function test_phase3_keys_registered_in_catalog(): void
    {
        $registry = $this->registry();

        foreach ([
            'article.create',
            'article.content.update',
            'article.seo_meta.update',
            'article.review.request',
            'project.task.create',
            'project.task.attach_article',
            'project.task.mark_completed',
            'seo.audit.run',
            'seo.project_task.create_from_issue',
            'keyword.assign_to_project',
            'keyword.vocabulary.save',
            'keyword.topic_cluster.sync',
            'prompt_result.attach',
        ] as $key) {
            self::assertTrue($registry->has($key), "Missing catalog key [{$key}]");
        }

        self::assertFalse($registry->has('project.task.create_from_seo_issue'));
        self::assertFalse($registry->has('wordpress.article.update'));
    }

    public function test_review_request_has_no_handler_blocker(): void
    {
        $registry = $this->registry();
        self::assertTrue($registry->has('article.review.request'));
        self::assertFalse($registry->hasHandler('article.review.request'));
        self::assertSame(
            'internal_only',
            $registry->definition('article.review.request')->selectability->value,
        );
    }

    public function test_wordpress_actions_remain_non_workflow_selectable(): void
    {
        $registry = $this->registry();
        $selectable = $registry->selectableKeys();

        self::assertNotContains('wordpress.article.publish', $selectable);
        self::assertNotContains('wordpress.article.sync_outbound', $selectable);
        self::assertContains('wordpress.comment_review.publish', $selectable);
        self::assertNotContains('article.review.request', $selectable);
    }

    public function test_content_update_dry_run_does_not_call_handler(): void
    {
        self::assertTrue(UpdateArticleContentAction::definition()->supportsDryRun);

        $registry = $this->registry();
        self::assertFalse($registry->hasHandler('article.content.update'));

        $logger = new class implements ActionExecutionLoggerContract
        {
            public function start(ActionContext $context, string $actionKey, ?string $entityType, ?int $entityId, array $input): void {}

            public function finish(string $executionId, ActionResult $result): void {}
        };
        $events = new class implements AutomationEventDispatcher
        {
            public function dispatch(EventEnvelope $event): void {}
        };

        $runner = new ActionRunner($registry, $logger, $events);
        $result = $runner->run(
            'article.content.update',
            ActionContext::fromArray([
                'origin' => 'automation.test',
                'dry_run' => true,
            ]),
            ['article_id' => 1, 'content' => '<p>x</p>'],
        );

        self::assertTrue($result->success);
        self::assertSame(ActionRunStatus::DryRun, $result->status);
        self::assertTrue((bool) ($result->output['dry_run'] ?? false));
        // No handler registered: if dry_run did not short-circuit, result would be handler_missing.
        self::assertNotSame('handler_missing', $result->error['code'] ?? null);
    }

    public function test_handler_registrar_lists_expected_phase3_actions(): void
    {
        $keys = array_map(
            static fn (string $class): string => $class::definition()->key,
            ActionHandlerRegistrar::handlers(),
        );

        self::assertContains('article.create', $keys);
        self::assertContains('article.content.update', $keys);
        self::assertContains('seo.project_task.create_from_issue', $keys);
        self::assertContains('keyword.topic_cluster.sync', $keys);
        self::assertContains('prompt_result.attach', $keys);
        self::assertNotContains('article.review.request', $keys);
        self::assertNotContains('wordpress.article.publish', $keys);
    }

    public function test_action_result_output_is_array_not_object_model(): void
    {
        $result = ActionResult::success(
            output: ['article_id' => 1, 'status' => 'draft'],
            status: ActionRunStatus::Succeeded,
        );

        $array = $result->toArray();
        self::assertIsArray($array['output']);
        self::assertSame(1, $array['output']['article_id']);
        self::assertIsNotObject($array['output']);
    }
}
