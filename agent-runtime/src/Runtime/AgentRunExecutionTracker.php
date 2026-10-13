<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Runtime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Services\AiUsageTaxonomy;
use Throwable;

final class AgentRunExecutionTracker
{
    private const CACHE_PREFIX = 'agent_run_status:';
    private const CACHE_TTL_SECONDS = 600;

    /** @var list<array<string, mixed>> */
    private array $attempts = [];

    private ?string $successfulModel = null;
    private ?string $successfulProvider = null;
    private ?string $successfulCostClass = null;

    /** @var array<int, float> */
    private array $attemptStartTimes = [];

    public function __construct(
        public readonly string $runUlid,
        public readonly int $userId,
        public readonly ?string $tier = null,
        public readonly ?string $routingPolicy = null,
    ) {
        $this->persistLiveState('processing');
    }

    public function recordAttemptStart(
        string $provider,
        string $connectionName,
        string $model,
        string $costClass,
    ): int {
        $attemptNumber = count($this->attempts) + 1;
        $this->attemptStartTimes[$attemptNumber] = hrtime(true);

        $this->attempts[] = [
            'attempt_id' => 'att_' . Str::lower((string) Str::ulid()),
            'attempt_number' => $attemptNumber,
            'provider' => $provider,
            'connection_name' => $connectionName,
            'model' => $model,
            'requested_model' => $model,
            'resolved_model' => null,
            'cost_class' => $costClass,
            'status' => 'running',
            'failure_reason' => null,
            'failure_code' => null,
            'http_status' => null,
            'duration_ms' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'reasoning_tokens' => null,
            'total_tokens' => null,
            'recorded_cost' => null,
            'currency' => null,
            'cost_state' => $costClass === 'free' ? 'confirmed' : 'pending',
        ];

        $this->persistLiveState('processing');

        return $attemptNumber;
    }

    /**
     * @param array<string, mixed>|null $usage
     */
    public function recordAttemptSuccess(
        int $attemptNumber,
        RoutedAiCandidate $routed,
        ?array $usage = null,
    ): void {
        $idx = $attemptNumber - 1;
        if (! isset($this->attempts[$idx])) {
            return;
        }

        $durationMs = isset($this->attemptStartTimes[$attemptNumber])
            ? (int) round((hrtime(true) - $this->attemptStartTimes[$attemptNumber]) / 1_000_000)
            : null;

        $resolvedModel = is_array($usage)
            ? (trim((string) ($usage['resolved_model'] ?? $usage['actual_provider_model'] ?? '')) ?: $routed->model)
            : $routed->model;

        $tokens = AiUsageTaxonomy::extractTokens($usage);
        $reasoningTokens = is_array($usage)
            ? ($usage['completion_tokens_details']['reasoning_tokens'] ?? $usage['reasoning_tokens'] ?? null)
            : null;

        $costInfo = $this->extractCost($usage, $routed->isFree);

        $this->attempts[$idx]['status'] = 'success';
        $this->attempts[$idx]['resolved_model'] = $resolvedModel;
        $this->attempts[$idx]['duration_ms'] = $durationMs;
        $this->attempts[$idx]['input_tokens'] = $tokens['input_tokens'];
        $this->attempts[$idx]['output_tokens'] = $tokens['output_tokens'];
        $this->attempts[$idx]['reasoning_tokens'] = is_numeric($reasoningTokens) ? (int) $reasoningTokens : null;
        $this->attempts[$idx]['total_tokens'] = $tokens['total_tokens'];
        $this->attempts[$idx]['recorded_cost'] = $costInfo['cost'];
        $this->attempts[$idx]['currency'] = $costInfo['currency'];
        $this->attempts[$idx]['cost_state'] = $costInfo['state'];

        $this->successfulModel = $resolvedModel;
        $this->successfulProvider = (string) $routed->provider;
        $this->successfulCostClass = $routed->isFree ? 'free' : 'paid';

        $this->persistLiveState('processing');
    }

    public function recordAttemptFailure(
        int $attemptNumber,
        RoutedAiCandidate $routed,
        Throwable $exception,
    ): void {
        $idx = $attemptNumber - 1;
        if (! isset($this->attempts[$idx])) {
            return;
        }

        $durationMs = isset($this->attemptStartTimes[$attemptNumber])
            ? (int) round((hrtime(true) - $this->attemptStartTimes[$attemptNumber]) / 1_000_000)
            : null;

        $httpStatus = $exception instanceof PromptRunException
            ? ($exception->context['http_status'] ?? null)
            : null;

        $failureReason = $exception instanceof PromptRunException
            ? ($exception->context['failure_code'] ?? $exception->context['failure_category'] ?? $exception->getMessage())
            : $exception->getMessage();

        $failureUsage = $exception instanceof PromptRunException && is_array($exception->context['token_usage'] ?? null)
            ? $exception->context['token_usage']
            : null;

        $tokens = AiUsageTaxonomy::extractTokens($failureUsage);
        $costInfo = $this->extractCost($failureUsage, $routed->isFree);

        $this->attempts[$idx]['status'] = 'failed';
        $this->attempts[$idx]['failure_reason'] = Str::limit((string) $failureReason, 200);
        $this->attempts[$idx]['http_status'] = is_numeric($httpStatus) ? (int) $httpStatus : null;
        $this->attempts[$idx]['duration_ms'] = $durationMs;
        $this->attempts[$idx]['input_tokens'] = $tokens['input_tokens'];
        $this->attempts[$idx]['output_tokens'] = $tokens['output_tokens'];
        $this->attempts[$idx]['total_tokens'] = $tokens['total_tokens'];
        $this->attempts[$idx]['recorded_cost'] = $costInfo['cost'];
        $this->attempts[$idx]['currency'] = $costInfo['currency'];
        $this->attempts[$idx]['cost_state'] = $costInfo['state'];

        $this->persistLiveState('processing');
    }

    /**
     * Merge final diagnostics from router attempts (e.g. precise failure classes, durations).
     *
     * @param list<array<string, mixed>> $routingAttempts
     * @param array<string, mixed>|null $finalUsage
     */
    public function syncFinalRoutingAttempts(
        array $routingAttempts,
        ?array $finalUsage = null,
        ?RoutedAiCandidate $winningCandidate = null,
    ): void {
        $executedRows = array_values(array_filter(
            $routingAttempts,
            static fn (array $row): bool => ($row['result'] ?? '') !== 'skipped' && ($row['attempted'] ?? true)
        ));

        foreach ($executedRows as $i => $row) {
            if (! isset($this->attempts[$i])) {
                $isFree = (bool) ($row['is_free'] ?? false);
                $this->attempts[$i] = [
                    'attempt_id' => 'att_' . Str::lower((string) Str::ulid()),
                    'attempt_number' => $i + 1,
                    'provider' => (string) ($row['provider'] ?? 'unknown'),
                    'connection_name' => (string) ($row['connection_name'] ?? ($row['provider'] ?? '')),
                    'model' => (string) ($row['model'] ?? 'unknown'),
                    'requested_model' => (string) ($row['requested_model'] ?? $row['model'] ?? ''),
                    'resolved_model' => (string) ($row['actual_provider_model'] ?? $row['resolved_model'] ?? $row['model'] ?? ''),
                    'cost_class' => $isFree ? 'free' : 'paid',
                    'status' => ($row['result'] ?? '') === 'success' ? 'success' : 'failed',
                    'failure_reason' => $row['failure_class'] ?? $row['skip_reason'] ?? null,
                    'http_status' => $row['http_status'] ?? null,
                    'duration_ms' => $row['duration_ms'] ?? null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'reasoning_tokens' => null,
                    'total_tokens' => null,
                    'recorded_cost' => null,
                    'currency' => null,
                    'cost_state' => $isFree ? 'confirmed' : 'pending',
                ];
            } else {
                if (! empty($row['actual_provider_model'])) {
                    $this->attempts[$i]['resolved_model'] = (string) $row['actual_provider_model'];
                }
                if (! empty($row['duration_ms']) && empty($this->attempts[$i]['duration_ms'])) {
                    $this->attempts[$i]['duration_ms'] = (int) $row['duration_ms'];
                }
                if (! empty($row['failure_class']) && empty($this->attempts[$i]['failure_reason'])) {
                    $this->attempts[$i]['failure_reason'] = (string) $row['failure_class'];
                }
            }
        }

        if ($winningCandidate instanceof RoutedAiCandidate && empty($this->successfulModel)) {
            $this->successfulModel = is_array($finalUsage)
                ? (trim((string) ($finalUsage['resolved_model'] ?? '')) ?: $winningCandidate->model)
                : $winningCandidate->model;
            $this->successfulProvider = (string) $winningCandidate->provider;
            $this->successfulCostClass = $winningCandidate->isFree ? 'free' : 'paid';
        }

        $this->persistLiveState('processing');
    }

    public function markDone(): void
    {
        $this->persistLiveState('done');
    }

    public function markFailed(?string $reason = null): void
    {
        $this->persistLiveState('failed', $reason);
    }

    public function markPaused(): void
    {
        $this->persistLiveState('paused');
    }

    /**
     * @return array<string, mixed>
     */
    public function toStatusPayload(string $runStatus = 'processing', ?string $errorMessage = null): array
    {
        $currentAttempt = null;
        for ($i = count($this->attempts) - 1; $i >= 0; $i--) {
            if ($this->attempts[$i]['status'] === 'running') {
                $currentAttempt = $this->attempts[$i];
                break;
            }
        }
        if ($currentAttempt === null && count($this->attempts) > 0) {
            $currentAttempt = end($this->attempts);
        }

        $usageSummary = $this->getUsageSummary();
        $costSummary = $this->getCostSummary();

        return [
            'run_status' => $runStatus,
            'run_ulid' => $this->runUlid,
            'tier' => $this->tier,
            'routing_policy' => $this->routingPolicy,
            'internal_only' => count($this->attempts) === 0 && in_array($runStatus, ['done', 'completed'], true),
            'attempts' => $this->attempts,
            'current_attempt' => $currentAttempt,
            'successful_model' => $this->successfulModel,
            'successful_provider' => $this->successfulProvider,
            'successful_cost_class' => $this->successfulCostClass,
            'usage' => [
                'input_tokens' => $usageSummary['input_tokens'],
                'output_tokens' => $usageSummary['output_tokens'],
                'reasoning_tokens' => $usageSummary['reasoning_tokens'],
                'total_tokens' => $usageSummary['total_tokens'],
                'recorded_cost' => $costSummary['recorded_cost'],
                'currency' => $costSummary['currency'],
                'state' => $costSummary['state'],
            ],
            'error_message' => $errorMessage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getExecutionSummary(): array
    {
        return [
            'attempts' => $this->attempts,
            'successful_model' => $this->successfulModel,
            'successful_provider' => $this->successfulProvider,
            'successful_cost_class' => $this->successfulCostClass,
            'attempted_models' => array_values(array_unique(array_filter(
                array_map(static fn (array $a): string => (string) ($a['model'] ?? ''), $this->attempts)
            ))),
            'usage' => $this->getUsageSummary(),
            'cost' => $this->getCostSummary(),
        ];
    }

    /**
     * @return array{input_tokens: ?int, output_tokens: ?int, reasoning_tokens: ?int, total_tokens: ?int}
     */
    public function getUsageSummary(): array
    {
        $in = 0;
        $out = 0;
        $reasoning = 0;
        $total = 0;
        $hasAnyTokens = false;

        foreach ($this->attempts as $attempt) {
            if ($attempt['input_tokens'] !== null) {
                $in += (int) $attempt['input_tokens'];
                $hasAnyTokens = true;
            }
            if ($attempt['output_tokens'] !== null) {
                $out += (int) $attempt['output_tokens'];
                $hasAnyTokens = true;
            }
            if (! empty($attempt['reasoning_tokens'])) {
                $reasoning += (int) $attempt['reasoning_tokens'];
                $hasAnyTokens = true;
            }
            if ($attempt['total_tokens'] !== null) {
                $total += (int) $attempt['total_tokens'];
                $hasAnyTokens = true;
            }
        }

        if (! $hasAnyTokens) {
            return [
                'input_tokens' => null,
                'output_tokens' => null,
                'reasoning_tokens' => null,
                'total_tokens' => null,
            ];
        }

        return [
            'input_tokens' => $in,
            'output_tokens' => $out,
            'reasoning_tokens' => $reasoning > 0 ? $reasoning : null,
            'total_tokens' => $total > 0 ? $total : ($in + $out),
        ];
    }

    /**
     * @return array{recorded_cost: ?float, currency: ?string, state: string}
     */
    public function getCostSummary(): array
    {
        if ($this->attempts === []) {
            return [
                'recorded_cost' => null,
                'currency' => null,
                'state' => 'unrecorded',
            ];
        }

        $allFree = true;
        $totalCost = 0.0;
        $hasConfirmedCost = false;
        $hasPendingPaidCost = false;

        foreach ($this->attempts as $attempt) {
            $isPaid = ($attempt['cost_class'] ?? 'free') === 'paid';
            if ($isPaid) {
                $allFree = false;
                if ($attempt['recorded_cost'] !== null && is_numeric($attempt['recorded_cost'])) {
                    $totalCost += (float) $attempt['recorded_cost'];
                    $hasConfirmedCost = true;
                } else {
                    $hasPendingPaidCost = true;
                }
            } else {
                // Free attempt confirmed 0 cost
                if ($attempt['recorded_cost'] !== null && (float) $attempt['recorded_cost'] === 0.0) {
                    $hasConfirmedCost = true;
                }
            }
        }

        if ($allFree) {
            return [
                'recorded_cost' => 0.0,
                'currency' => 'USD',
                'state' => 'confirmed',
            ];
        }

        if ($hasPendingPaidCost) {
            return [
                'recorded_cost' => $hasConfirmedCost ? round($totalCost, 6) : null,
                'currency' => 'USD',
                'state' => 'pending',
            ];
        }

        if ($hasConfirmedCost) {
            return [
                'recorded_cost' => round($totalCost, 6),
                'currency' => 'USD',
                'state' => 'confirmed',
            ];
        }

        return [
            'recorded_cost' => null,
            'currency' => null,
            'state' => 'unrecorded',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAttempts(): array
    {
        return $this->attempts;
    }

    public function getSuccessfulModel(): ?string
    {
        return $this->successfulModel;
    }

    public function getSuccessfulProvider(): ?string
    {
        return $this->successfulProvider;
    }

    public function getSuccessfulCostClass(): ?string
    {
        return $this->successfulCostClass;
    }

    public function markInternalOnly(): void
    {
        $this->persistLiveState('done');
    }

    public function markCompleted(?string $errorMessage = null): void
    {
        $status = $errorMessage !== null ? 'failed' : 'done';
        $this->persistLiveState($status, $errorMessage);
    }

    /**
     * @return list<string>
     */
    public function getAttemptedModels(): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (array $a): string => (string) ($a['model'] ?? ''), $this->attempts)
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExecutedAttempts(): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (array $a): bool => in_array($a['status'] ?? '', ['running', 'success', 'failed'], true)
        ));
    }

    /**
     * Extract authoritative cost from provider usage bag.
     *
     * @param array<string, mixed>|null $usage
     * @return array{cost: ?float, currency: ?string, state: string}
     */
    private function extractCost(?array $usage, bool $isFree): array
    {
        if ($isFree) {
            return ['cost' => 0.0, 'currency' => 'USD', 'state' => 'confirmed'];
        }

        if (! is_array($usage) || $usage === []) {
            return ['cost' => null, 'currency' => null, 'state' => 'pending'];
        }

        $costRaw = $usage['total_cost'] ?? $usage['cost'] ?? $usage['cost_usd'] ?? null;
        if ($costRaw !== null && is_numeric($costRaw)) {
            return ['cost' => round((float) $costRaw, 6), 'currency' => 'USD', 'state' => 'confirmed'];
        }

        return ['cost' => null, 'currency' => null, 'state' => 'pending'];
    }

    private function persistLiveState(string $runStatus = 'processing', ?string $errorMessage = null): void
    {
        $payload = $this->toStatusPayload($runStatus, $errorMessage);

        try {
            Cache::put(self::CACHE_PREFIX . $this->runUlid, $payload, self::CACHE_TTL_SECONDS);
        } catch (Throwable) {}

        try {
            $run = AgentRun::where('ulid', $this->runUlid)->first();
            if ($run instanceof AgentRun) {
                $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
                $summary['live_execution'] = $payload;
                $run->update(['retrieval_summary' => $summary]);
            }
        } catch (Throwable) {}
    }

    /**
     * Retrieve status for an AgentRun model (from Cache if live, else from DB retrieval_summary).
     *
     * @return array<string, mixed>
     */
    public static function statusFor(AgentRun $run): array
    {
        $cached = Cache::get(self::CACHE_PREFIX . $run->ulid);
        if (is_array($cached) && ! empty($cached)) {
            // Update run_status if run already completed in DB
            if (in_array($run->status, ['done', 'failed', 'awaiting_model', 'awaiting_confirmation'], true)) {
                $cached['run_status'] = $run->status === 'done' ? 'done' : ($run->status === 'failed' ? 'failed' : 'paused');
            }
            return $cached;
        }

        $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
        if (is_array($summary['live_execution'] ?? null)) {
            $data = $summary['live_execution'];
            if (in_array($run->status, ['done', 'failed'], true)) {
                $data['run_status'] = $run->status;
            }
            return $data;
        }

        $execution = is_array($summary['model_diagnostics']['execution'] ?? null)
            ? $summary['model_diagnostics']['execution']
            : (is_array($summary['execution'] ?? null) ? $summary['execution'] : []);

        $attempts = is_array($execution['attempts'] ?? null) ? $execution['attempts'] : [];
        $usage = is_array($execution['usage'] ?? null) ? $execution['usage'] : [
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'reasoning_tokens' => null,
            'total_tokens' => $run->total_tokens,
        ];
        $cost = is_array($execution['cost'] ?? null) ? $execution['cost'] : [
            'recorded_cost' => null,
            'currency' => null,
            'state' => 'unrecorded',
        ];

        return [
            'run_status' => $run->status === 'done' ? 'done' : ($run->status === 'failed' ? 'failed' : 'processing'),
            'run_ulid' => $run->ulid,
            'tier' => $summary['tier'] ?? ($execution['tier'] ?? 'free'),
            'routing_policy' => $summary['routing_policy'] ?? ($execution['routing_policy'] ?? 'free_only'),
            'internal_only' => ($execution['internal_only'] ?? null) === true || (count($attempts) === 0 && ($execution['synthesis'] ?? true) === false),
            'attempts' => $attempts,
            'current_attempt' => end($attempts) ?: null,
            'successful_model' => $execution['successful_model'] ?? $run->answer_model,
            'successful_provider' => $execution['successful_provider'] ?? null,
            'successful_cost_class' => $execution['successful_cost_class'] ?? null,
            'usage' => [
                'input_tokens' => $usage['input_tokens'] ?? $run->input_tokens,
                'output_tokens' => $usage['output_tokens'] ?? $run->output_tokens,
                'reasoning_tokens' => $usage['reasoning_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? $run->total_tokens,
                'recorded_cost' => $cost['recorded_cost'] ?? null,
                'currency' => $cost['currency'] ?? null,
                'state' => $cost['state'] ?? 'unrecorded',
            ],
            'error_message' => $run->error_message,
        ];
    }
}
