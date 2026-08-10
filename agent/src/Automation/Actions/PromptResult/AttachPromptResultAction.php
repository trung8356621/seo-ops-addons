<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Actions\PromptResult;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRiskLevel;
use Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSelectability;
use Omnichannel\Addons\Agent\Automation\Enums\ActionSideEffect;
use Omnichannel\Addons\Agent\Automation\Support\ActionSupport;
use Omnichannel\Addons\Seo\Contracts\PromptResultAttacher;
use Omnichannel\Addons\AiPrompt\Services\PromptResultAttachService;
use InvalidArgumentException;

/**
 * Attach PromptResult audit artifact to article|project_task|project.
 * Local-only — no WordPress, no unrelated events.
 */
final class AttachPromptResultAction implements BusinessAction
{
    public function __construct(
        private readonly PromptResultAttacher $attacher,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'prompt_result.attach',
            name: 'Attach PromptResult to domain target',
            description: 'Idempotent link PromptResult → article|project_task|project. No WP sync.',
            module: 'prompt_result',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Low,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'prompt_result_id' => ['type' => 'integer', 'required' => true],
                'target_type' => ['type' => 'string', 'required' => true],
                'target_id' => ['type' => 'integer', 'required' => true],
                'site_id' => ['type' => 'integer', 'required' => true],
                'relation' => ['type' => 'string', 'required' => false],
                'purpose' => ['type' => 'string', 'required' => false],
            ],
            outputSchema: [
                'attached' => ['type' => 'boolean'],
                'deduplicated' => ['type' => 'boolean'],
                'prompt_result_id' => ['type' => 'integer'],
                'target_type' => ['type' => 'string'],
                'target_id' => ['type' => 'integer'],
            ],
            idempotent: true,
            supportsDryRun: true,
            emittedEvents: [],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $promptResultId = (int) ($input['prompt_result_id'] ?? 0);
        $targetType = trim((string) ($input['target_type'] ?? ''));
        $targetId = (int) ($input['target_id'] ?? 0);
        $siteId = (int) ($input['site_id'] ?? $context->siteId ?? 0);
        $purpose = trim((string) ($input['purpose'] ?? $input['relation'] ?? 'prompt_result.attach'));
        if ($purpose === '') {
            $purpose = 'prompt_result.attach';
        }

        if ($promptResultId <= 0 || $targetId <= 0 || $siteId <= 0) {
            return ActionResult::failure('invalid_input', 'prompt_result_id, target_id, and site_id are required.');
        }

        if (! in_array($targetType, PromptResultAttachService::ALLOWED_TARGETS, true)) {
            return ActionResult::failure(
                'target_not_allowed',
                "target_type [{$targetType}] is not in allowlist.",
            );
        }

        if ($context->siteId !== null && (int) $context->siteId > 0 && (int) $context->siteId !== $siteId) {
            return ActionResult::failure('wrong_context', 'site_id does not match action context.');
        }

        if ($context->dryRun) {
            return ActionResult::success(
                output: [
                    'attached' => true,
                    'deduplicated' => false,
                    'prompt_result_id' => $promptResultId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'dry_run' => true,
                ],
                status: ActionRunStatus::DryRun,
            );
        }

        try {
            $out = $this->attacher->attach(
                promptResultId: $promptResultId,
                targetType: $targetType,
                targetId: $targetId,
                siteId: $siteId,
                purpose: $purpose,
                meta: [
                    'relation' => (string) ($input['relation'] ?? $purpose),
                    'origin' => $context->origin,
                    'correlation_id' => $context->correlationId,
                ],
            );
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage();
            $code = str_contains(strtolower($message), 'mismatch') || str_contains(strtolower($message), 'wrong context')
                ? 'wrong_context'
                : 'attach_failed';

            return ActionResult::failure($code, $message);
        }

        return ActionResult::success(
            output: $out,
            changed: ['prompt_result_link'],
        );
    }
}
