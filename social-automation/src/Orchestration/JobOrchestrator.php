<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Orchestration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AttemptFencePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\JobIdempotencyKey;
use Omnichannel\Addons\SocialAutomation\Domain\JobStateMachine;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\SessionLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\WorkflowJobComposer;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;
use Omnichannel\Addons\SocialAutomation\Enums\AgentAvailability;
use Omnichannel\Addons\SocialAutomation\Enums\JobStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaJobAttempt;
use Omnichannel\Addons\SocialAutomation\Models\SaJobEvent;
use Omnichannel\Addons\SocialAutomation\Models\SaSessionAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaTask;
use Omnichannel\Addons\SocialAutomation\Support\JobObservability;
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
        private readonly AccountLockPolicy $accountLocks = new AccountLockPolicy,
        private readonly AttemptFencePolicy $fence = new AttemptFencePolicy,
        private readonly JobObservability $observability = new JobObservability,
        private readonly WorkflowJobComposer $workflowComposer = new WorkflowJobComposer,
        private readonly ?PackageRegistryService $packages = null,
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

        try {
            // Eligibility + reserve under one transaction so concurrent dispatches
            // cannot both assign the same account (account row lock inside reserve).
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($task, $target): array {
                $selection = $this->selectEligible($task, $target);
                $created = [];
                foreach ($selection['selected'] as $row) {
                    $job = $this->reserveJob($task, $row, null, 'manual');
                    if ($job instanceof SaJob) {
                        $created[] = $job->job_uuid;
                    }
                }

                return [
                    'ok' => true,
                    'result' => [
                        'target_count' => $selection['target_count'],
                        'dispatched_count' => count($created),
                        'shortfall' => max(0, $selection['target_count'] - count($created)),
                        'job_uuids' => $created,
                        'skip_reasons' => $selection['skip_reasons'],
                    ],
                ];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['dispatch_failed']];
        }
    }

    /**
     * @return array{ok: bool, job?: array<string, mixed>, attempt?: array<string, mixed>, errors?: list<string>}
     */
    public function claim(string $agentUuid, string $jobUuid, ?int $credentialId): array
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $agent = $this->authorizedAgent($agentUuid, $credentialId);
        if ($agent === null) {
            return ['ok' => false, 'errors' => ['agent_not_found']];
        }
        if ((string) $agent->availability === AgentAvailability::Disabled->value) {
            return ['ok' => false, 'errors' => ['agent_disabled']];
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

                // Idempotent: same active claim returns current fence.
                if ($from === JobStatus::Claimed || $from === JobStatus::Running) {
                    $active = $this->activeFence($job);

                    return [
                        'ok' => true,
                        'job' => $this->jobArray($job),
                        'attempt' => $active === null ? null : [
                            'attempt_uuid' => $active,
                            'attempt_no' => $this->fenceAttemptNo($job),
                        ],
                        'idempotent' => true,
                    ];
                }

                if (! $this->states->canTransition($from, JobStatus::Claimed)) {
                    return ['ok' => false, 'errors' => ['invalid_transition']];
                }

                // Capacity was enforced at reserve time; claim only advances the existing reservation.

                $attemptNo = (int) SaJobAttempt::query()->where('job_id', $job->id)->max('attempt_no') + 1;
                $attempt = SaJobAttempt::query()->create([
                    'attempt_uuid' => (string) Str::uuid(),
                    'job_id' => $job->id,
                    'attempt_no' => max(1, $attemptNo),
                    'status' => 'started',
                    'started_at' => now(),
                ]);

                $this->transition($job, JobStatus::Claimed, 'claim', [
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
                ], releaseLock: false, fenceAttemptUuid: (string) $attempt->attempt_uuid, fenceAttemptNo: (int) $attempt->attempt_no);

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
        ], requireFence: true);
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

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($agentUuid, $jobUuid, $payload, $credentialId): array {
                $job = $this->authorizedJob($agentUuid, $jobUuid, $credentialId, forUpdate: true);
                if ($job === null) {
                    return ['ok' => false, 'errors' => ['job_not_found_or_forbidden']];
                }

                $fenceCheck = $this->fence->validate(
                    isset($payload['attempt_uuid']) ? (string) $payload['attempt_uuid'] : null,
                    $this->activeFence($job),
                );
                if (! ($fenceCheck['ok'] ?? false)) {
                    return ['ok' => false, 'errors' => [$fenceCheck['error'] ?? AttemptFencePolicy::ERR_MISMATCH]];
                }

                SaJobEvent::query()->create([
                    'job_id' => $job->id,
                    'event_type' => 'progress',
                    'from_status' => $job->status,
                    'to_status' => $job->status,
                    'payload' => $this->observability->redactArray([
                        'message' => isset($payload['message']) ? substr((string) $payload['message'], 0, 500) : null,
                        'percent' => $payload['percent'] ?? null,
                        'step' => $payload['step'] ?? null,
                        'attempt_uuid' => $payload['attempt_uuid'] ?? null,
                    ]),
                    'occurred_at' => now(),
                ]);

                $job->lease_expires_at = now()->addSeconds(self::DEFAULT_LEASE_SECONDS);
                $job->save();

                return ['ok' => true, 'job' => $this->jobArray($job)];
            });
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['progress_failed']];
        }
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
            'result' => is_array($payload['result'] ?? null)
                ? $this->observability->redactArray($payload['result'])
                : null,
            'error_code' => $payload['error_code'] ?? null,
            'error_message' => isset($payload['error_message']) ? substr((string) $payload['error_message'], 0, 2000) : null,
            'finished_at' => now(),
        ], releaseLock: true, requireFence: true);
    }

    /**
     * Lease reconciliation — never blind-retry social side effects.
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
            try {
                DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use ($job, &$count): void {
                    $locked = SaJob::query()->where('id', $job->id)->lockForUpdate()->first();
                    if (! $locked instanceof SaJob) {
                        return;
                    }
                    if ($locked->lease_expires_at === null || $locked->lease_expires_at->isFuture()) {
                        return;
                    }
                    $from = JobStatus::from((string) $locked->status);
                    $to = $this->states->onLeaseExpired($from);
                    if ($to === null) {
                        return;
                    }

                    $this->markActiveAttemptStale($locked);
                    // Unknown keeps session/account lock (uncertain outcome — no auto re-dispatch).
                    // Pending (never claimed) releases locks so another dispatch may use the account.
                    $this->transition(
                        $locked,
                        $to,
                        'lease_expired',
                        [],
                        releaseLock: $to !== JobStatus::Unknown,
                        clearFence: true,
                    );
                    $count++;
                });
            } catch (Throwable) {
                continue;
            }
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
     * @return array<string, mixed>|null
     */
    public function jobTimeline(string $jobUuid): ?array
    {
        if (! SocialAutomationSchema::jobsReady()) {
            return null;
        }
        $job = SaJob::query()->where('job_uuid', $jobUuid)->first();
        if (! $job instanceof SaJob) {
            return null;
        }
        $attempts = SaJobAttempt::query()->where('job_id', $job->id)->orderBy('attempt_no')->get()
            ->map(static fn (SaJobAttempt $a): array => [
                'attempt_uuid' => (string) $a->attempt_uuid,
                'attempt_no' => (int) $a->attempt_no,
                'status' => (string) $a->status,
                'started_at' => $a->started_at?->toIso8601String(),
                'finished_at' => $a->finished_at?->toIso8601String(),
                'error_code' => $a->error_code,
            ])->all();
        $events = SaJobEvent::query()->where('job_id', $job->id)->orderBy('occurred_at')->get()
            ->map(static fn (SaJobEvent $e): array => [
                'event_type' => (string) $e->event_type,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'payload' => is_array($e->payload) ? $e->payload : [],
            ])->all();
        $account = null;
        if ($job->account_id) {
            $acc = SaAccount::query()->find($job->account_id);
            if ($acc instanceof SaAccount) {
                $account = [
                    'account_uuid' => $acc->account_uuid,
                    'platform' => $acc->platform,
                    'platform_account_id' => $acc->platform_account_id,
                    'verified' => (bool) $acc->verified,
                ];
            }
        }
        $agent = null;
        if ($job->agent_id) {
            $ag = SaAgent::query()->find($job->agent_id);
            if ($ag instanceof SaAgent) {
                $agent = [
                    'agent_uuid' => $ag->agent_uuid,
                    'device_id' => $ag->device_id,
                    'runtime_version' => $ag->runtime_version,
                ];
            }
        }

        return $this->observability->build($this->jobArray($job), $attempts, $events, $account, $agent);
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

        $busyAccountIds = SaJob::query()
            ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->all();

        $sessions = [];
        foreach (SaBrowserSession::query()->get() as $session) {
            $activeBind = SaSessionAccount::query()
                ->where('browser_session_id', $session->id)
                ->where('is_active', true)
                ->first();
            $accountMeta = null;
            $accountId = $activeBind?->account_id;
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
                'account_locked' => $accountId !== null
                    && $this->accountLocks->isAccountBusy($accountId, $busyAccountIds),
                'platform' => $session->platform,
                'domain' => $session->domain,
                'account' => $accountMeta,
                'capabilities' => is_array($session->capabilities) ? $session->capabilities : [],
                'account_id' => $accountId,
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

        $agent = SaAgent::query()->lockForUpdate()->find($session->agent_id);
        if (! $agent instanceof SaAgent) {
            return null;
        }
        if (in_array((string) $agent->availability, [
            AgentAvailability::Disabled->value,
            AgentAvailability::Paused->value,
            AgentAvailability::Error->value,
        ], true)) {
            return null;
        }

        $agentJobs = SaJob::query()
            ->where('agent_id', $session->agent_id)
            ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
            ->get(['status'])->map(fn (SaJob $j): array => ['status' => $j->status])->all();
        if (! $this->locks->agentHasCapacity($agentJobs, (int) $agent->max_concurrency)) {
            return null;
        }

        $accountId = $sessionRow['account_id'] ?? null;
        if ($accountId === null || (int) $accountId <= 0) {
            return null;
        }

        // Atomic account exclusivity: lock account row then re-check active jobs.
        $account = SaAccount::query()->lockForUpdate()->find($accountId);
        if (! $account instanceof SaAccount) {
            return null;
        }
        $accountJobs = SaJob::query()
            ->where('account_id', $accountId)
            ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
            ->lockForUpdate()
            ->get(['id', 'status'])
            ->map(static fn (SaJob $j): array => [
                'job_id' => $j->id,
                'status' => $j->status,
                'account_id' => $accountId,
            ])->all();
        if (! $this->accountLocks->canAssign($accountId, $accountJobs)) {
            return null;
        }

        $idem = JobIdempotencyKey::forDispatch(
            (string) $task->task_uuid,
            (string) ($sessionRow['session_id'] ?? $session->id),
            $triggerId,
            $accountId,
        );

        $existing = SaJob::query()->where('idempotency_key', $idem)->lockForUpdate()->first();
        if ($existing instanceof SaJob) {
            return $existing;
        }

        $release = null;
        $registry = $this->packages;
        if ($registry === null && function_exists('app')) {
            try {
                $registry = app(PackageRegistryService::class);
            } catch (Throwable) {
                $registry = null;
            }
        }
        if ($registry instanceof PackageRegistryService) {
            $release = $registry->findRelease((string) $task->package_key, (string) $task->package_version);
        }

        $composed = $this->workflowComposer->compose(
            (string) $task->package_kind,
            is_array($task->inputs) ? $task->inputs : [],
            $release,
        );
        $payload = $composed['payload'];
        if (! ($composed['ok'] ?? false)) {
            $payload['composition_errors'] = $composed['errors'];
        }

        $job = SaJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'task_id' => $task->id,
            'schedule_trigger_id' => $triggerId,
            'agent_id' => $session->agent_id,
            'browser_session_id' => $session->id,
            'account_id' => $accountId,
            'package_key' => $task->package_key,
            'package_version' => $task->package_version,
            'content_digest' => $task->content_digest,
            'status' => JobStatus::Reserved->value,
            'idempotency_key' => $idem,
            'payload' => $payload,
            'reserved_at' => now(),
            'lease_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
            'dispatch_source' => $source,
        ]);

        $session->fill([
            'locked_job_id' => $job->id,
            'locked_at' => now(),
            'lock_expires_at' => now()->addSeconds(self::DEFAULT_LEASE_SECONDS),
        ])->save();

        $agent->active_jobs = (int) $agent->active_jobs + 1;
        if ((int) $agent->active_jobs >= max(1, (int) $agent->max_concurrency)) {
            $agent->availability = AgentAvailability::Busy->value;
        }
        $agent->save();

        $this->recordEvent($job, null, JobStatus::Reserved->value, 'reserve');

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attrs
     * @return array{ok: bool, job?: array<string, mixed>, errors?: list<string>, idempotent?: bool}
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
        bool $requireFence = false,
    ): array {
        if (! SocialAutomationSchema::jobsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        try {
            return DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $agentUuid, $jobUuid, $to, $event, $payload, $credentialId, $attrs, $releaseLock, $requireFence
            ): array {
                $job = $this->authorizedJob($agentUuid, $jobUuid, $credentialId, forUpdate: true);
                if ($job === null) {
                    return ['ok' => false, 'errors' => ['job_not_found_or_forbidden']];
                }

                $presented = isset($payload['attempt_uuid']) ? (string) $payload['attempt_uuid'] : null;
                if ($requireFence) {
                    if ($this->fence->isDuplicateTerminal($presented, $this->activeFence($job), (string) $job->status)) {
                        return ['ok' => true, 'job' => $this->jobArray($job), 'idempotent' => true];
                    }
                    $fenceCheck = $this->fence->validate($presented, $this->activeFence($job));
                    if (! ($fenceCheck['ok'] ?? false)) {
                        return ['ok' => false, 'errors' => [$fenceCheck['error'] ?? AttemptFencePolicy::ERR_MISMATCH]];
                    }
                }

                $from = JobStatus::from((string) $job->status);
                if (! $this->states->canTransition($from, $to)) {
                    return ['ok' => false, 'errors' => ['invalid_transition']];
                }

                $this->transition($job, $to, $event, $attrs, $releaseLock);

                if ($to->isTerminal() || $to === JobStatus::HumanRequired) {
                    $this->finishActiveAttempt($job, $to, $payload);
                }

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
        ?string $fenceAttemptUuid = null,
        ?int $fenceAttemptNo = null,
        bool $clearFence = false,
    ): void {
        $from = (string) $job->status;
        foreach ($attrs as $k => $v) {
            $job->{$k} = $v;
        }
        if ($fenceAttemptUuid !== null) {
            $this->writeFence($job, $fenceAttemptUuid, $fenceAttemptNo ?? 1);
        }
        if ($clearFence) {
            $this->writeFence($job, null, null);
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
            $session = SaBrowserSession::query()->lockForUpdate()->find($job->browser_session_id);
            if ($session instanceof SaBrowserSession && (int) $session->locked_job_id === (int) $job->id) {
                $session->fill([
                    'locked_job_id' => null,
                    'locked_at' => null,
                    'lock_expires_at' => null,
                ])->save();
            }
        }
        if ($job->agent_id) {
            $agent = SaAgent::query()->lockForUpdate()->find($job->agent_id);
            if ($agent instanceof SaAgent) {
                $agent->active_jobs = max(0, (int) $agent->active_jobs - 1);
                if ($agent->active_jobs === 0 && $agent->availability === AgentAvailability::Busy->value) {
                    $agent->availability = AgentAvailability::Available->value;
                }
                $agent->save();
            }
        }
    }

    private function authorizedAgent(string $agentUuid, ?int $credentialId): ?SaAgent
    {
        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return null;
        }
        if ($credentialId !== null && $agent->credential_id !== null
            && (int) $agent->credential_id !== $credentialId) {
            return null;
        }

        return $agent;
    }

    private function authorizedJob(
        string $agentUuid,
        string $jobUuid,
        ?int $credentialId,
        bool $forUpdate = false,
    ): ?SaJob {
        $agent = $this->authorizedAgent($agentUuid, $credentialId);
        if ($agent === null) {
            return null;
        }
        if ((string) $agent->availability === AgentAvailability::Disabled->value) {
            // Disabled agents may still finish an in-flight fenced attempt (no new claim).
            // Ownership check continues; claim() separately rejects new claims.
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

    private function activeFence(SaJob $job): ?string
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $uuid = $payload['_fence']['attempt_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    private function fenceAttemptNo(SaJob $job): int
    {
        $payload = is_array($job->payload) ? $job->payload : [];

        return max(1, (int) ($payload['_fence']['attempt_no'] ?? 1));
    }

    private function writeFence(SaJob $job, ?string $attemptUuid, ?int $attemptNo): void
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        if ($attemptUuid === null) {
            unset($payload['_fence']);
        } else {
            $payload['_fence'] = [
                'attempt_uuid' => $attemptUuid,
                'attempt_no' => $attemptNo ?? 1,
            ];
        }
        $job->payload = $payload;
    }

    private function markActiveAttemptStale(SaJob $job): void
    {
        $uuid = $this->activeFence($job);
        if ($uuid === null) {
            return;
        }
        SaJobAttempt::query()
            ->where('job_id', $job->id)
            ->where('attempt_uuid', $uuid)
            ->where('status', 'started')
            ->update([
                'status' => 'stale',
                'finished_at' => now(),
                'error_code' => 'lease_expired',
            ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finishActiveAttempt(SaJob $job, JobStatus $to, array $payload): void
    {
        $uuid = $this->activeFence($job);
        if ($uuid === null) {
            return;
        }
        $attempt = SaJobAttempt::query()
            ->where('job_id', $job->id)
            ->where('attempt_uuid', $uuid)
            ->first();
        if (! $attempt instanceof SaJobAttempt) {
            return;
        }
        $attempt->fill([
            'status' => $to->value,
            'finished_at' => now(),
            'result' => is_array($payload['result'] ?? null) ? $this->observability->redactArray($payload['result']) : null,
            'error_code' => $payload['error_code'] ?? null,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function jobArray(SaJob $job): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $publicPayload = $payload;
        // Expose fence attempt_uuid to the owning Runner; strip nothing else sensitive here.
        return [
            'job_uuid' => $job->job_uuid,
            'task_id' => $job->task_id,
            'status' => $job->status,
            'package_key' => $job->package_key,
            'package_version' => $job->package_version,
            'content_digest' => $job->content_digest,
            'account_id' => $job->account_id,
            'dispatch_source' => $job->dispatch_source,
            'attempt_uuid' => $publicPayload['_fence']['attempt_uuid'] ?? null,
            'reserved_at' => $job->reserved_at?->toIso8601String(),
            'claimed_at' => $job->claimed_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
            'duration_ms' => $job->duration_ms,
            'error_code' => $job->error_code,
            'error_message' => $job->error_message,
            'payload' => $this->observability->redactArray($publicPayload),
            'result' => $job->result,
        ];
    }
}
