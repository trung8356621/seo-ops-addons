<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentMessage;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentRun;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\AgentRuntime\Runtime\AgentToolConfirmationProposal;

class AgentTurnPersistence
{
    public function persistUserMessage(
        AgentThread $thread,
        string $content,
    ): AgentMessage {
        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException('User message content must not be empty.');
        }

        $position = AgentMessage::where('thread_id', $thread->id)->max('position') ?? 0;

        return AgentMessage::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $thread->id,
            'role' => 'user',
            'content' => $content,
            'position' => $position + 1,
        ]);
    }

    public function startRun(
        AgentThread $thread,
        AgentMessage $userMessage,
        string $appKey,
        string $scopeType,
        string $scopeRef,
        ?int $userId,
    ): AgentRun {
        return AgentRun::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $thread->id,
            'user_message_id' => $userMessage->id,
            'app_key' => $appKey,
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
            'user_id' => $userId,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function startRerun(
        AgentThread $thread,
        AgentMessage $userMessage,
        string $appKey,
        string $scopeType,
        string $scopeRef,
        ?int $userId,
    ): AgentRun {
        return $this->startRun($thread, $userMessage, $appKey, $scopeType, $scopeRef, $userId);
    }

    public function completeRun(
        AgentRun $run,
        AgentResponse $response,
        array $meta = [],
    ): AgentMessage {
        $position = AgentMessage::where('thread_id', $run->thread_id)->max('position') ?? 0;

        $assistantMessage = AgentMessage::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $run->thread_id,
            'run_id' => $run->id,
            'role' => 'assistant',
            'content' => $response->message,
            'response_payload' => [
                'message' => $response->message,
                'blocks' => $response->blocks,
                'actions' => $response->actions,
                'sources' => $response->sources,
            ],
            'position' => $position + 1,
        ]);

        $run->update(array_merge([
            'status' => 'done',
            'assistant_message_id' => $assistantMessage->id,
            'finished_at' => now(),
        ], $meta));

        return $assistantMessage;
    }

    /** @param array<string, mixed> $state */
    public function pauseRun(AgentRun $run, string $callKey, array $state): void
    {
        $run->update([
            'status' => 'awaiting_model',
            'retrieval_summary' => [
                'model_call' => $callKey,
                'runtime_state' => $state,
            ],
        ]);
    }

    /** @param array<string, mixed> $runtimeState */
    public function pauseForConfirmation(AgentRun $run, AgentToolConfirmationProposal $proposal, array $runtimeState = []): void
    {
        $confirmation = ['proposal' => $proposal->toArray()];
        if ($runtimeState !== []) {
            $confirmation['runtime_state'] = $runtimeState;
        }
        $run->update([
            'status' => 'awaiting_confirmation',
            'retrieval_summary' => [
                'confirmation' => $confirmation,
            ],
        ]);
    }

    /** @param array<string, mixed> $state */
    public function pauseForGscContinuation(AgentRun $run, array $state): void
    {
        $run->update([
            'status' => 'awaiting_gsc_continuation',
            'retrieval_summary' => [
                'gsc_continuation' => $state,
            ],
        ]);
    }

    /** @param array<string, mixed> $state */
    public function rememberContinuation(AgentRun $run, AgentResponse $response, array $state): AgentMessage
    {
        $position = AgentMessage::where('thread_id', $run->thread_id)->max('position') ?? 0;
        $assistantMessage = AgentMessage::create([
            'ulid' => (string) Str::ulid(),
            'thread_id' => $run->thread_id,
            'run_id' => $run->id,
            'role' => 'assistant',
            'content' => $response->message,
            'response_payload' => [
                'message' => $response->message,
                'blocks' => $response->blocks,
                'actions' => $response->actions,
                'sources' => $response->sources,
            ],
            'position' => $position + 1,
        ]);
        $run->update([
            'status' => 'awaiting_gsc_continuation',
            'assistant_message_id' => $assistantMessage->id,
            'retrieval_summary' => [
                'gsc_continuation' => $state,
            ],
        ]);

        return $assistantMessage;
    }

    public function claimAwaitingGscContinuation(string $runUlid, int $userId): ?AgentRun
    {
        return DB::transaction(function () use ($runUlid, $userId): ?AgentRun {
            $run = AgentRun::query()
                ->where('ulid', $runUlid)
                ->where('user_id', $userId)
                ->where('status', 'awaiting_gsc_continuation')
                ->lockForUpdate()
                ->first();
            if (! $run instanceof AgentRun) {
                return null;
            }
            $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
            if (! is_array($summary['gsc_continuation']['proposal'] ?? null)) {
                return null;
            }
            $run->update(['status' => 'running']);

            return $run;
        });
    }

    public function resumeRun(AgentRun $run): void
    {
        $run->update(['status' => 'running']);
    }

    public function claimAwaitingConfirmation(string $runUlid, int $userId, bool $requireProposal = true): ?AgentRun
    {
        return DB::transaction(function () use ($runUlid, $userId, $requireProposal): ?AgentRun {
            $run = AgentRun::query()
                ->where('ulid', $runUlid)
                ->where('user_id', $userId)
                ->where('status', 'awaiting_confirmation')
                ->lockForUpdate()
                ->first();
            if (! $run instanceof AgentRun) {
                return null;
            }

            $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
            if ($requireProposal && ! is_array($summary['confirmation']['proposal'] ?? null)) {
                return null;
            }

            $run->update(['status' => 'running']);

            return $run->fresh();
        });
    }

    /** @param array<string, mixed> $diagnostics */
    public function storeAnswerDiagnostics(AgentRun $run, array $diagnostics): void
    {
        $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
        $summary['answer_diagnostics'] = $diagnostics;
        $run->update(['retrieval_summary' => $summary]);
    }

    /** @param array<string, mixed> $diagnostics */
    public function storeModelDiagnostics(AgentRun $run, array $diagnostics): void
    {
        $summary = is_array($run->retrieval_summary) ? $run->retrieval_summary : [];
        $summary['model_diagnostics'] = $diagnostics;
        $run->update(['retrieval_summary' => $summary]);
    }

    public function failRun(
        AgentRun $run,
        string $failureCode,
        ?string $errorMessage = null,
    ): void {
        $run->update([
            'status' => 'failed',
            'failure_code' => $failureCode,
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ]);
    }
}
