<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Orchestration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\JobStateMachine;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\SessionLockPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\JobStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaJobEvent;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaTask;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

final class JobOrchestrator
{
    public const DEFAULT_LEASE_SECONDS = 300;

    public function __construct(
        private readonly EligibilitySelector $eligibility = new EligibilitySelector,
        private readonly JobStateMachine $states = new JobStateMachine,
        private readonly SessionLockPolicy $locks = new SessionLockPolicy,
        private readonly OnlineStateCalculator $online = new OnlineStateCalculator,
    ) {}

    /**
     * @return array{ok: bool, result?: array<string, mixed>, errors?: list<string>}
     */
    public function manualDispatch(SaTask $task, ?int $targetOverride = null): array
    {
        if (! SocialAutomationSchema::jobsReady() || ! SocialAutomationSchema::agentsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $target = $targetOverride ?? (int) $task->target_account_count;
        $selection = $this->selectEligible($task, $target);

        try {
            $jobs = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($task, $selection): array {
                $created = [];
                foreach ($selection['selected'] as $row) {
                    $job = $this->reserveJob($task, $row, null, 'manual');
                    if ($job instanceof SaJob) {
                        $created[] = $job->job_uuid;
                    }
                }

                return $created;
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['dispatch_failed']];
        }

        return [
            'ok' => true,
            'result' => [
                'target_count' => $selection['target_count'],
                'dispatched_count' => count($jobs),
                'shortfall' => max(0, $selection['target_count'] - count($jobs)),
                'job_uuids' => $jobs,
                'skip_reasons' => $selection['skip_reasons'],
            ],
        ];
    }

    /**
     * @return array{ok: bool, job?: array<string, mixed>, attempt?: array<string, mixed>, errors?: list<string>}
     */
    public function claim(string $agentUuid, string $jobUuid, ?int $credentialId): array
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return ['ok' => false, 'errors' => ['agent_not_found']];
        }
        if ($credentialId !== null && $agent->credential_id !== null
            && (int) $agent->credential_id !== $credentialId) {
            return ['ok' => false, 'errors' => ['credential_mismatch']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($agent, $jobUuid): array {
                $job = SaJob::query()->where('job_uuid', $jobUuid)->lockForUpdate()->first();
                if (! $job instanceof SaJob) {
                    return ['ok' => false, 'errors' => ['job_not_found']];
                }
                if ((int) $job->agent_id !== (int) $agent->id) {
                    return ['ok' => false, 'errors' => ['cross_agent_forbidden']];
                }

                $from = JobStatus::from((string) $job->status);
                if (! $this->states->canTransition($from, JobStatus::Claimed)) {
                    // Idempotent reclaim
                    if ($from === JobStatus::Claimed || $from === JobStatus::Running) {
                        return ['ok' => true, 'job' => $this->jobArray($job)];
                    }

                    return ['ok' => false, 'errors' => ['invalid_transition']];
                }

                $this->transition($job, JobStatus::Claimed, 'claim', [
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
                ]);

                $attemptNo = (int) SaJobAttempt::query()->where('job_id', $job->id)->max('attempt_no') + 1;
                $attempt = SaJobAttempt::query()->create([
                    'attempt_uuid' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'attempt_no' => max(1, $attemptNo),
                    'status' => 'started',
                    'started_at' => now(),
                ]);

                return [
                    'ok' => true,
                    'job' => $this->jobArray($job->fresh() ?? $job),
                    'attempt' => [
                        'attempt_uuid' => $attempt->attempt_uuid,
                        'attempt_no' => $attempt->attempt_no,
                    ],
                ];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['claim_failed']];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, job?: array<string, mixed>, errors?: list<string>}
     */
    public function acknowledge(string $agentUuid, string $jobUuid, array $payload, ?int $credentialId): array
    {
        return $this->advance($agentUuid, $jobUuid, JobStatus::Running, 'ack', $payload, $credentialId, [
            'started_at' => now(),
            'lease_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, job?: array<string, mixed>, errors?: list<string>}
     */
    public function progress(string $agentUuid, string $jobUuid, array $payload, ?int $credentialId): array
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $job = $this->authorizedJob($agentUuid, $jobUuid, $credentialId);
        if ($job === null) {
            return ['ok' => false, 'errors' => ['job_not_found_or_forbidden']];
        }

        SaJobEvent::query()->create([
            'job_id' => $job->id,
            'event_type' => 'progress',
            'from_status' => $job->status,
            'to_status' => $job->status,
            'payload' => [
                'message' => isset($payload['message']) ? substr((string) $payload['message'], 0, 500) : null,
                'percent' => $payload['percent'] ?? null,
                'step' => $payload['step'] ?? null,
            ],
            'occurred_at' => now(),
        ]);

        $job->lease_expires_at = now()->addSeconds(self::DEFAULT_LEASE_SECONDS);
        $job->save();

        return ['ok' => true, 'job' => $this->jobArray($job)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, job?: array<string, mixed>, errors?: list<string>}
     */
    public function complete(string $agentUuid, string $jobUuid, array $payload, ?int $credentialId): array
    {
        $status = match (strtolower((string) ($payload['status'] ?? 'completed'))) {
            'failed' => JobStatus::Failed,
            'human_required' => JobStatus::HumanRequired,
            default => JobStatus::Completed,
        };

        return $this->advance($agentUuid, $jobUuid, $status, 'result', $payload, $credentialId, [
            'result' => is_array($payload['result'] ?? null) ? $payload['result'] : null,
            'error_code' => $payload['error_code'] ?? null,
            'error_message' => isset($payload['error_message']) ? substr((string) $payload['error_message'], 0, 2000) : null,
            'finished_at' => now(),
        ], releaseLock: true);
    }

    /**
     * Lease reconciliation — never blind-retry publish side effects.
     *
     * @return array{reconciled: int}
     */
    public function reconcileExpiredLeases(): array
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return ['reconciled' => 0];
        }

        $count = 0;
        $jobs = SaJob::query()
            ->whereIn('status', [
                JobStatus::Reserved->value,
                JobStatus::Claimed->value,
                JobStatus::Running->value,
            ])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->limit(100)
            ->get();

        foreach ($jobs as $job) {
            $from = JobStatus::from((string) $job->status);
            $to = $this->states->onLeaseExpired($from);
            if ($to === null) {
                continue;
            }
            $this->transition($job, $to, 'lease_expired', [], releaseLock: $to !== JobStatus::Unknown);
            $count++;
        }

        return ['reconciled' => $count];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listJobsForUi(int $limit = 100): array
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return [];
        }

        return SaJob::query()->orderByDesc('id')->limit($limit)->get()
            ->map(fn (SaJob $j): array => $this->jobArray($j))
            ->all();
    }

    /**
     * @return array{selected: list<array<string, mixed>>, target_count: int, skip_reasons: list<string>}
     */
    public function selectEligible(SaTask $task, int $target): array
    {
        $agents = SaAgent::query()->get()->map(function (SaAgent $a): array {
            return [
                'agent_id' => $a->id,
                'online' => $this->online->isOnline($a->last_heartbeat_at),
                'availability' => (string) $a->availability,
                'active_jobs' => (int) $a->active_jobs,
                'max_concurrency' => max(1, (int) $a->max_concurrency),
                'capabilities' => is_array($a->capabilities) ? $a->capabilities : [],
                'runtime_version' => $a->runtime_version,
            ];
        })->all();

        $sessions = [];
        foreach (SaBrowserSession::query()->get() as $session) {
            $activeBind = SaSessionAccount::query()
                ->where('browser_session_id', $session->id)
                ->where('is_active', true)
                ->first();
            $accountMeta = null;
            if ($activeBind instanceof SaSessionAccount) {
                $account = SaAccount::query()->find($activeBind->account_id);
                if ($account instanceof SaAccount) {
                    $accountMeta = [
                        'platform' => $account->platform,
                        'domain' => $account->domain,
                        'platform_account_id' => $account->platform_account_id,
                        'verified' => (bool) $account->verified,
                        'identity_source' => $account->identity_source,
                    ];
                }
            }

            $busy = $session->locked_job_id !== null
                && ($session->lock_expires_at === null || $session->lock_expires_at->isFuture());

            $sessions[] = [
                'session_id' => $session->id,
                'agent_id' => $session->agent_id,
                'ready_status' => $session->ready_status,
                'busy' => $busy,
                'platform' => $session->platform,
                'domain' => $session->domain,
                'account' => $accountMeta,
                'capabilities' => is_array($session->capabilities) ? $session->capabilities : [],
                'account_id' => $activeBind?->account_id,
            ];
        }

        $criteria = array_merge(
            is_array($task->eligibility) ? $task->eligibility : [],
            ['target_count' => $target]
        );

        return $this->eligibility->select($agents, $sessions, $criteria);
    }

    /**
     * @param  array<string, mixed>  $sessionRow
     */
    public function reserveJob(
        SaTask $task,
        array $sessionRow,
        ?int $triggerId,
        string $source,
    ): ?SaJob {
        $session = SaBrowserSession::query()->lockForUpdate()->find($sessionRow['session_id'] ?? null);
        if (! $session instanceof SaBrowserSession) {
            return null;
        }

        $tempJobId = 0;
        if (! $this->locks->canLock([
            'locked_job_id' => $session->locked_job_id,
            'lock_expires_at' => $session->lock_expires_at,
        ], $tempJobId)) {
            return null;
        }

        $agentJobs = SaJob::query()
            ->where('agent_id', $session->agent_id)
            ->whereIn('status', ['reserved', 'claimed', 'running', 'unknown'])
            ->get(['status'])->map(fn (SaJob $j): array => ['status' => $j->status])->all();
        if ($this->locks->agentHasActiveJob($agentJobs)) {
            return null;
        }

        $idem = hash('sha256', implode('|', [
            $task->task_uuid,
            (string) ($sessionRow['session_id'] ?? ''),
            (string) ($triggerId ?? 'manual'),
            (string) microtime(true),
            (string) Str::uuid(),
        ]));

        $job = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'task_id' => $task->id,
            'schedule_trigger_id' => $triggerId,
            'agent_id' => $session->agent_id,
            'browser_session_id' => $session->id,
            'account_id' => $sessionRow['account_id'] ?? null,
            'package_key' => $task->package_key,
            'package_version' => $task->package_version,
            'content_digest' => $task->content_digest,
            'status' => JobStatus::Reserved->value,
            'idempotency_key' => $idem,
            'payload' => [
                'inputs' => $task->inputs,
                'package_kind' => $task->package_kind,
            ],
            'reserved_at' => now(),
            'lease_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
            'dispatch_source' => $source,
        ]);

        $session->fill([
            'locked_job_id' => $job->id,
            'locked_at' => now(),
            'lock_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
        ])->save();

        $agent = SaAgent::query()->find($session->agent_id);
        if ($agent instanceof SaAgent) {
            $agent->active_jobs = (int) $agent->active_jobs + 1;
            $agent->availability = 'busy';
            $agent->save();
        }

        $this->recordEvent($job, null, JobStatus::Reserved->value, 'reserve');

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attrs
     * @return array{ok: bool, job?: array<string, mixed>, errors?: list<string>}
     */
    private function advance(
        string $agentUuid,
        string $jobUuid,
        JobStatus $to,
        string $event,
        array $payload,
        ?int $credentialId,
        array $attrs = [],
        bool $releaseLock = false,
    ): array {
        if (! SocialAutomationSchema::jobsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($agentUuid, $jobUuid, $to, $event, $credentialId, $attrs, $releaseLock): array {
                $job = $this->authorizedJob($agentUuid, $jobUuid, $credentialId, forUpdate: true);
                if ($job === null) {
                    return ['ok' => false, 'errors' => ['job_not_found_or_forbidden']];
                }
                $from = JobStatus::from((string) $job->status);
                if (! $this->states->canTransition($from, $to)) {
                    return ['ok' => false, 'errors' => ['invalid_transition']];
                }
                $this->transition($job, $to, $event, $attrs, $releaseLock);

                return ['ok' => true, 'job' => $this->jobArray($job->fresh() ?? $job)];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['advance_failed']];
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function transition(
        SaJob $job,
        JobStatus $to,
        string $event,
        array $attrs = [],
        bool $releaseLock = false,
    ): void {
        $from = (string) $job->status;
        foreach ($attrs as $k => $v) {
            $job->{$k} = $v;
        }
        if ($to->isTerminal() && $job->started_at && $job->finished_at) {
            $job->duration_ms = max(0, ((int) $job->finished_at->getTimestamp() - (int) $job->started_at->getTimestamp()) * 1000);
        }
        $job->status = $to->value;
        $job->save();
        $this->recordEvent($job, $from, $to->value, $event);

        if ($releaseLock || $to->isTerminal()) {
            $this->releaseSession($job);
        }
    }

    private function releaseSession(SaJob $job): void
    {
        if ($job->browser_session_id) {
            $session = SaBrowserSession::query()->find($job->browser_session_id);
            if ($session instanceof SaBrowserSession && (int) $session->locked_job_id === (int) $job->id) {
                $session->fill([
                    'locked_job_id' => null,
                    'locked_at' => null,
                    'lock_expires_at' => null,
                ])->save();
            }
        }
        if ($job->agent_id) {
            $agent = SaAgent::query()->find($job->agent_id);
            if ($agent instanceof SaAgent) {
                $agent->active_jobs = max(0, (int) $agent->active_jobs - 1);
                if ($agent->active_jobs === 0 && $agent->availability === 'busy') {
                    $agent->availability = 'available';
                }
                $agent->save();
            }
        }
    }

    private function authorizedJob(
        string $agentUuid,
        string $jobUuid,
        ?int $credentialId,
        bool $forUpdate = false,
    ): ?SaJob {
        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return null;
        }
        if ($credentialId !== null && $agent->credential_id !== null
            && (int) $agent->credential_id !== $credentialId) {
            return null;
        }
        $q = SaJob::query()->where('job_uuid', $jobUuid)->where('agent_id', $agent->id);
        if ($forUpdate) {
            $q->lockForUpdate();
        }
        $job = $q->first();

        return $job instanceof SaJob ? $job : null;
    }

    private function recordEvent(SaJob $job, ?string $from, string $to, string $type): void
    {
        SaJobEvent::query()->create([
            'job_id' => $job->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'payload' => null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobArray(SaJob $job): array
    {
        return [
            'job_uuid' => $job->job_uuid,
            'task_id' => $job->task_id,
            'status' => $job->status,
            'package_key' => $job->package_key,
            'package_version' => $job->package_version,
            'dispatch_source' => $job->dispatch_source,
            'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
            'error_code' => $job->error_code,
            'payload' => $job->payload,
            'result' => $job->result,
        ];
    }
}
