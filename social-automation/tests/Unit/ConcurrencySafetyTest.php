<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AttemptFencePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\JobIdempotencyKey;
use Omnichannel\Addons\SocialAutomation\Domain\JobStateMachine;
use Omnichannel\Addons\SocialAutomation\Domain\SessionLockPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\JobStatus;
use Omnichannel\Addons\SocialAutomation\Support\JobObservability;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationApiStatus;
use PHPUnit\Framework\TestCase;

/**
 * Concurrency / fencing invariants as pure unit proofs.
 * MySQL transaction races: PENDING FINAL INTEGRATION (no dedicated test DB assumed).
 */
final class ConcurrencySafetyTest extends TestCase
{
    public function test_account_lock_blocks_second_active_job(): void
    {
        $p = new AccountLockPolicy;
        self::assertTrue($p->canAssign(10, []));
        self::assertFalse($p->canAssign(10, [
            ['job_id' => 1, 'account_id' => 10, 'status' => 'running'],
        ]));
        self::assertTrue($p->canAssign(10, [
            ['job_id' => 1, 'account_id' => 10, 'status' => 'running'],
        ], exceptJobId: 1));
        self::assertFalse($p->canAssign(null, []));
    }

    public function test_two_sessions_same_account_only_one_eligible(): void
    {
        $sel = new EligibilitySelector;
        $acc = [
            'platform' => 'facebook',
            'platform_account_id' => 'same-1',
            'verified' => true,
            'identity_source' => 'platform_id',
        ];
        $result = $sel->select(
            [
                ['agent_id' => 1, 'online' => true, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 2],
                ['agent_id' => 2, 'online' => true, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 2],
            ],
            [
                ['session_id' => 1, 'agent_id' => 1, 'ready_status' => 'ready', 'busy' => false, 'platform' => 'facebook', 'account' => $acc, 'account_id' => 9],
                ['session_id' => 2, 'agent_id' => 2, 'ready_status' => 'ready', 'busy' => false, 'platform' => 'facebook', 'account' => $acc, 'account_id' => 9],
            ],
            ['platform' => 'facebook', 'target_count' => 2]
        );
        self::assertSame(1, $result['dispatched_count']);
    }

    public function test_account_locked_flag_skips_selection(): void
    {
        $sel = new EligibilitySelector;
        $acc = [
            'platform' => 'facebook',
            'platform_account_id' => 'x',
            'verified' => true,
            'identity_source' => 'platform_id',
        ];
        $result = $sel->select(
            [['agent_id' => 1, 'online' => true, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 1]],
            [[
                'session_id' => 1,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'account_locked' => true,
                'platform' => 'facebook',
                'account' => $acc,
            ]],
            ['platform' => 'facebook', 'target_count' => 1]
        );
        self::assertSame(0, $result['dispatched_count']);
        self::assertContains('account_locked', $result['skip_reasons']);
    }

    public function test_stale_attempt_rejected_after_fence_cleared(): void
    {
        $f = new AttemptFencePolicy;
        $stale = $f->validate('attempt-old', null);
        self::assertFalse($stale['ok']);
        self::assertSame(AttemptFencePolicy::ERR_STALE, $stale['error']);

        $mismatch = $f->validate('attempt-old', 'attempt-new');
        self::assertFalse($mismatch['ok']);
        self::assertSame(AttemptFencePolicy::ERR_MISMATCH, $mismatch['error']);

        $ok = $f->validate('attempt-new', 'attempt-new');
        self::assertTrue($ok['ok']);
    }

    public function test_duplicate_terminal_result_idempotent(): void
    {
        $f = new AttemptFencePolicy;
        self::assertTrue($f->isDuplicateTerminal('a', 'a', 'completed'));
        self::assertFalse($f->isDuplicateTerminal('a', 'b', 'completed'));
        self::assertFalse($f->isDuplicateTerminal('a', 'a', 'running'));
    }

    public function test_unknown_cannot_complete_without_reclaim(): void
    {
        $sm = new JobStateMachine;
        self::assertFalse($sm->canTransition(JobStatus::Unknown, JobStatus::Completed));
        self::assertFalse($sm->canTransition(JobStatus::Unknown, JobStatus::Failed));
        self::assertTrue($sm->canTransition(JobStatus::Unknown, JobStatus::Claimed));
    }

    public function test_retry_dispatch_idempotency_key_stable(): void
    {
        $a = JobIdempotencyKey::forDispatch('t1', 5, null, 99);
        $b = JobIdempotencyKey::forDispatch('t1', 5, null, 99);
        self::assertSame($a, $b);
    }

    public function test_agent_capacity_multi_account(): void
    {
        $locks = new SessionLockPolicy;
        self::assertTrue($locks->agentHasCapacity([['status' => 'running']], 3));
        self::assertTrue($locks->agentHasCapacity(
            [['status' => 'running'], ['status' => 'claimed']],
            3
        ));
        self::assertFalse($locks->agentHasCapacity(
            [['status' => 'running'], ['status' => 'claimed'], ['status' => 'reserved']],
            3
        ));
    }

    public function test_api_status_mapping_stable(): void
    {
        self::assertSame(409, SocialAutomationApiStatus::fromResult(false, ['stale_attempt']));
        self::assertSame(403, SocialAutomationApiStatus::fromResult(false, ['credential_mismatch']));
        self::assertSame(404, SocialAutomationApiStatus::fromResult(false, ['job_not_found']));
        self::assertSame(503, SocialAutomationApiStatus::fromResult(false, ['schema_unavailable']));
        self::assertSame(422, SocialAutomationApiStatus::fromResult(false, ['validation_failed']));
        self::assertSame(201, SocialAutomationApiStatus::fromResult(true, [], 201));
    }

    public function test_observability_redacts_secrets(): void
    {
        $o = new JobObservability;
        $dto = $o->build(
            [
                'job_uuid' => 'j1',
                'status' => 'failed',
                'error_code' => 'session_timeout',
                'error_message' => 'Bearer abc',
                'package_key' => 'w',
                'package_version' => '1.0.0',
            ],
            [['attempt_uuid' => 'a1', 'attempt_no' => 1, 'status' => 'stale', 'error_code' => 'lease_expired']],
            [['event_type' => 'progress', 'payload' => ['cookie' => 'x', 'step' => '1']]],
            ['account_uuid' => 'acc', 'platform' => 'facebook', 'platform_account_id' => '1', 'verified' => true],
            ['agent_uuid' => 'ag', 'device_id' => 'd1', 'runtime_version' => '1.0.0']
        );
        self::assertSame('[redacted]', $dto['failure']['error_message']);
        self::assertSame('[redacted]', $dto['timeline'][0]['payload']['cookie']);
        self::assertSame('timeout', $dto['failure']['classification']);
        self::assertArrayNotHasKey('declared_email', $dto['account'] ?? []);
    }
}
