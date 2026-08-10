<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services\SideEffect;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationNodeExecution;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class WordPressSideEffectGuard
{
    /**
     * @param  array<string, mixed>  $payload  Non-secret metadata only
     */
    public function assertAllowed(
        ?WordPressExecutionContext $context,
        string $operation,
        array $payload = [],
    ): void {
        if ($context === null) {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_MISSING,
                'WordPress mutating request missing execution context.',
                $operation,
                $payload,
                null,
            );
        }

        if ($context instanceof AutomationWordPressContext) {
            $this->assertAutomation($context, $operation, $payload);

            return;
        }

        if ($context instanceof ManualWordPressContext) {
            $this->assertManual($context, $operation, $payload);

            return;
        }

        if ($context instanceof SystemWordPressContext) {
            $this->assertSystemReadOnly($context, $operation, $payload);

            return;
        }

        $this->block(
            UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
            'WordPress context origin must be automation, manual, or system (read-only).',
            $operation,
            $payload,
            $context,
        );
    }

    /**
     * System origin: reconcile/find probes only — never mutate WordPress.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertSystemReadOnly(
        SystemWordPressContext $context,
        string $operation,
        array $payload,
    ): void {
        $allowed = [
            'article.find_post_by_meta',
            'article.get_post',
            'article.find_by_operation_key',
        ];

        if (! in_array($operation, $allowed, true)) {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'System WordPress context may only run read/reconcile operations.',
                $operation,
                $payload,
                $context,
            );
        }

        if ($context->articleId() === null || (int) $context->articleId() <= 0) {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'System WordPress context requires article_id.',
                $operation,
                $payload,
                $context,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertAutomation(
        AutomationWordPressContext $context,
        string $operation,
        array $payload,
    ): void {
        $execution = AutomationExecution::query()->find($context->automationExecutionId);
        if (! $execution instanceof AutomationExecution) {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                "Automation execution [{$context->automationExecutionId}] not found.",
                $operation,
                $payload,
                $context,
            );
        }

        if (in_array((string) $execution->status, [
            AutomationExecutionStatus::Cancelled->value,
            AutomationExecutionStatus::Skipped->value,
        ], true) || $execution->isCancellationRequested()) {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'Automation execution cancelled/skipped — WordPress blocked.',
                $operation,
                $payload,
                $context,
            );
        }

        $eventUuid = (string) (($execution->context['event_uuid'] ?? '') ?: '');
        if ($eventUuid !== '' && $eventUuid !== $context->businessEventUuid) {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'business_event_uuid mismatch.',
                $operation,
                $payload,
                $context,
            );
        }

        if ($context->automationNodeExecutionId !== null && $context->automationNodeExecutionId > 0) {
            $node = AutomationNodeExecution::query()->find($context->automationNodeExecutionId);
            if (! $node instanceof AutomationNodeExecution
                || (int) $node->automation_execution_id !== (int) $execution->id
            ) {
                $this->block(
                    UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                    'automation_node_execution missing or mismatched.',
                    $operation,
                    $payload,
                    $context,
                );
            }
            if (in_array((string) $node->status, [
                AutomationNodeExecutionStatus::Cancelled->value,
                AutomationNodeExecutionStatus::Failed->value,
            ], true)) {
                $this->block(
                    UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                    'Node execution cancelled/failed.',
                    $operation,
                    $payload,
                    $context,
                );
            }
        } else {
            $manualActionCode = (string) (
                $execution->action_code
                ?? $execution->context['action_code']
                ?? $execution->context['manual_action']['action_code']
                ?? ''
            );
            $wpMutatingActions = [
                AutomationActionCode::WordpressArticleSync->value,
                AutomationActionCode::WordpressCommentReviewPublish->value,
                AutomationActionCode::ProductReviewSyncWp->value,
            ];
            $manualWp = in_array($manualActionCode, $wpMutatingActions, true);

            $rule = $execution->rule()->with(['actions', 'nodes'])->first();
            $ruleHasWp = $rule !== null && (
                $rule->actions->contains(
                    static fn ($a): bool => in_array((string) $a->action_code, $wpMutatingActions, true),
                )
                || $rule->nodes->contains(
                    static fn ($n): bool => in_array((string) ($n->action_code ?? ''), $wpMutatingActions, true),
                )
            );

            if (! $ruleHasWp && ! $manualWp) {
                $this->block(
                    UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                    'No WordPress mutating action (article.sync / comment_review.publish) bound to execution.',
                    $operation,
                    $payload,
                    $context,
                );
            }
        }

        $ctxArticle = $context->articleId();
        $payloadArticle = isset($payload['article_id']) ? (int) $payload['article_id'] : null;
        if ($payloadArticle !== null && $payloadArticle > 0 && $ctxArticle !== $payloadArticle) {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'article_id mismatch between context and payload.',
                $operation,
                $payload,
                $context,
            );
        }

        if ($context->idempotencyKey === '') {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'idempotency_key required.',
                $operation,
                $payload,
                $context,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertManual(
        ManualWordPressContext $context,
        string $operation,
        array $payload,
    ): void {
        $authId = Auth::id();
        if ($authId === null && ! app()->runningInConsole()) {
            // Queue worker: auth may be empty — trust serialized user_id from manual enqueue audit only.
            if ($context->userId <= 0 || $context->requestId === '') {
                $this->block(
                    UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                    'Manual context requires user_id + request_id on queue.',
                    $operation,
                    $payload,
                    $context,
                );
            }
        } elseif ($authId !== null && (int) $authId !== $context->userId && ! app()->runningInConsole()) {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'Authenticated user does not match manual context user_id.',
                $operation,
                $payload,
                $context,
            );
        }

        if ($context->userId <= 0 || $context->requestId === '' || $context->reason === '') {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'Manual context incomplete.',
                $operation,
                $payload,
                $context,
            );
        }

        // Permission: content managers cannot mutate WP.
        if (! app()->runningInConsole() && SeoAccessControl::isContentManager()) {
            $this->block(
                UnauthorizedWordPressSideEffectException::ORIGIN_INVALID,
                'Content manager cannot mutate WordPress.',
                $operation,
                $payload,
                $context,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function block(
        string $code,
        string $message,
        string $operation,
        array $payload,
        ?WordPressExecutionContext $context,
    ): never {
        $trace = $this->compactAppBacktrace();
        $log = [
            'error_code' => $code,
            'operation' => $operation,
            'article_id' => $context?->articleId() ?? ($payload['article_id'] ?? null),
            'site_id' => $context?->siteId() ?? ($payload['site_id'] ?? null),
            'origin' => $context?->origin() ?? 'missing',
            'correlation_id' => $context?->correlationId() ?? null,
            'automation_execution_id' => $context instanceof AutomationWordPressContext
                ? $context->automationExecutionId
                : null,
            'request_id' => $context instanceof ManualWordPressContext ? $context->requestId : null,
            'authenticated_user' => Auth::id(),
            'queue_job_class' => $this->currentQueueJobClass(),
            'queue_name' => $this->currentQueueName(),
            'request_route' => request()->route()?->getName() ?? request()->path(),
            'command_name' => $this->currentCommandName(),
            'compact_backtrace' => $trace,
            'message' => $message,
        ];

        Log::channel('wordpress-side-effect')->error('wordpress.side_effect.blocked', $log);
        Log::error('wordpress.side_effect.blocked', $log);

        app(WordPressSideEffectLedger::class)->recordBlocked(
            operation: $operation,
            context: $context,
            reason: $code.': '.$message,
            correlationId: $context?->correlationId() ?? ($payload['correlation_id'] ?? null),
        );

        throw new UnauthorizedWordPressSideEffectException($code, $message, $log);
    }

    /**
     * @return list<string>
     */
    private function compactAppBacktrace(): array
    {
        $frames = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', $file);
            if (str_contains($normalized, '/vendor/')) {
                continue;
            }
            if (! str_contains($normalized, '/app/')
                && ! str_contains($normalized, '/modules/')
                && ! str_contains($normalized, '/plugins/')
            ) {
                continue;
            }
            $short = preg_replace('#^.*/(app|modules|plugins)/#', '$1/', $normalized) ?? $normalized;
            $fn = (string) ($frame['function'] ?? '');
            $class = isset($frame['class']) ? (string) $frame['class'].'::' : '';
            $line = (int) ($frame['line'] ?? 0);
            $frames[] = "{$short}:{$line} {$class}{$fn}";
            if (count($frames) >= 16) {
                break;
            }
        }

        return $frames;
    }

    private function currentQueueJobClass(): ?string
    {
        try {
            $job = method_exists(app('queue.worker') ?? null, '') ? null : null;
        } catch (\Throwable) {
            $job = null;
        }

        if (app()->bound('queue.worker.job')) {
            $current = app('queue.worker.job');
            if (is_object($current)) {
                return $current::class;
            }
        }

        return null;
    }

    private function currentQueueName(): ?string
    {
        return null;
    }

    private function currentCommandName(): ?string
    {
        if (! app()->runningInConsole()) {
            return null;
        }

        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) ? implode(' ', array_slice($argv, 0, 3)) : null;
    }
}
