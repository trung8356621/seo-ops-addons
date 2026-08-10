<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Runtime;

use Omnichannel\Addons\Agent\Automation\Contracts\ActionExecutionLoggerContract;
use Omnichannel\Addons\Agent\Automation\Contracts\AutomationEventDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Agent\Automation\Registry\ActionRegistry;
use Throwable;

final class ActionRunner
{
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly ActionExecutionLoggerContract $logger,
        private readonly AutomationEventDispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(string $key, ActionContext $context, array $input = []): ActionResult
    {
        $definition = $this->registry->definition($key);

        if ($context->isWorkflowOrRuleOrigin()) {
            if ($definition->selectability !== ActionSelectability::Selectable) {
                throw AutomationException::notSelectable($key);
            }
        }

        if ($definition->selectability === ActionSelectability::LegacyNotSelectable
            && $context->isWorkflowOrRuleOrigin()) {
            throw AutomationException::notSelectable($key);
        }

        $validationErrors = $this->registry->validate($key, $input);
        if ($validationErrors !== []) {
            throw AutomationException::invalidInput($key, implode(' ', $validationErrors));
        }

        if ($key === 'wordpress.article.publish') {
            $intent = $context->publishIntent;
            if ($intent === null || ! $intent->allowsArticlePublishAction()) {
                throw AutomationException::publishIntentRequired($key);
            }
        }

        $entityType = isset($input['article_id']) ? 'article' : null;
        $entityId = isset($input['article_id']) ? (int) $input['article_id'] : null;

        $this->logger->start(
            context: $context,
            actionKey: $key,
            entityType: $entityType,
            entityId: $entityId,
            input: $input,
        );

        if ($context->dryRun && $definition->supportsDryRun) {
            $result = ActionResult::success(
                output: ['dry_run' => true, 'action_key' => $key],
                status: ActionRunStatus::DryRun,
            );
            $this->logger->finish($context->executionId, $result);

            return $result;
        }

        if (! $this->registry->hasHandler($key)) {
            $result = ActionResult::failure(
                code: 'handler_missing',
                message: "Automation action [{$key}] has no executable handler yet.",
            );
            $this->logger->finish($context->executionId, $result);

            return $result;
        }

        try {
            $handler = $this->registry->get($key);
            $result = $handler->execute($context, $input);
            if ($result->success) {
                $this->dispatchEvents($result);
            }
            $this->logger->finish($context->executionId, $result);

            return $result;
        } catch (Throwable $exception) {
            $result = ActionResult::failure(
                code: 'execution_failed',
                message: $exception->getMessage(),
                error: ['exception' => $exception::class],
            );
            $this->logger->finish($context->executionId, $result);

            throw $exception;
        }
    }

    private function dispatchEvents(ActionResult $result): void
    {
        foreach ($result->events as $event) {
            if ($event instanceof EventEnvelope) {
                $this->events->dispatch($event);
            }
        }
    }
}
