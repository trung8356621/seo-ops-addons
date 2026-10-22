<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Artifacts\ArtifactAcceptPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AccountDeduplicator;
use Omnichannel\Addons\SocialAutomation\Domain\AccountIdentityKey;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\JobStateMachine;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\SessionLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\WorkflowOutputMapper;
use Omnichannel\Addons\SocialAutomation\Enums\JobStatus;
use Omnichannel\Addons\SocialAutomation\Scheduling\CronSkipPolicy;
use PHPUnit\Framework\TestCase;

final class DomainPoliciesTest extends TestCase
{
    public function test_online_state_from_heartbeat_age(): void
    {
        $calc = new OnlineStateCalculator(90);
        $now = new \DateTimeImmutable('2026-10-21T12:00:00Z');
        self::assertTrue($calc->isOnline(new \DateTimeImmutable('2026-10-21T11:59:30Z'), $now));
        self::assertFalse($calc->isOnline(new \DateTimeImmutable('2026-10-21T11:58:00Z'), $now));
        self::assertSame('offline', $calc->state(null, $now));
    }

    public function test_account_identity_declared_alone_not_strong(): void
    {
        self::assertNull(AccountIdentityKey::strongKey([
            'platform' => 'facebook',
            'declared_email' => 'a@b.com',
            'verified' => false,
        ]));
        self::assertNotNull(AccountIdentityKey::strongKey([
            'platform' => 'facebook',
            'platform_account_id' => '123',
            'verified' => true,
            'identity_source' => 'platform_id',
        ]));
        self::assertFalse(AccountIdentityKey::definitelyDifferent(
            ['platform' => 'facebook', 'declared_username' => 'x'],
            ['platform' => 'facebook', 'declared_username' => 'y'],
        ));
    }

    public function test_dedupe_keeps_incomplete_and_unique_strong(): void
    {
        $d = new AccountDeduplicator;
        $out = $d->uniqueByStrongIdentity([
            ['session_id' => 1, 'account' => ['platform' => 'facebook', 'platform_account_id' => '1', 'verified' => true, 'identity_source' => 'platform_id']],
            ['session_id' => 2, 'account' => ['platform' => 'facebook', 'platform_account_id' => '1', 'verified' => true, 'identity_source' => 'platform_id']],
            ['session_id' => 3, 'account' => ['platform' => 'facebook', 'declared_username' => 'anon']],
        ]);
        self::assertCount(2, $out);
    }

    public function test_eligibility_respects_offline_and_shortfall(): void
    {
        $sel = new EligibilitySelector;
        $strong = static fn (string $id): array => [
            'platform' => 'facebook',
            'platform_account_id' => $id,
            'verified' => true,
            'identity_source' => 'platform_id',
        ];
        $result = $sel->select(
            [
                ['agent_id' => 1, 'online' => false, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 1],
                ['agent_id' => 2, 'online' => true, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 1, 'runtime_version' => '1.2.0'],
            ],
            [
                ['session_id' => 10, 'agent_id' => 1, 'ready_status' => 'ready', 'busy' => false, 'platform' => 'facebook', 'account' => $strong('a')],
                ['session_id' => 20, 'agent_id' => 2, 'ready_status' => 'ready', 'busy' => false, 'platform' => 'facebook', 'account' => $strong('b')],
                ['session_id' => 21, 'agent_id' => 2, 'ready_status' => 'ready', 'busy' => false, 'platform' => 'facebook', 'account' => $strong('c')],
            ],
            ['platform' => 'facebook', 'target_count' => 10, 'runtime_min' => '1.0.0']
        );
        // Agent 2 max_concurrency=1 → only one of sessions 20/21; agent 1 offline.
        self::assertSame(1, $result['dispatched_count']);
        self::assertSame(9, $result['shortfall']);
        self::assertContains('agent_offline', $result['skip_reasons']);
    }

    public function test_eligibility_skips_incomplete_identity(): void
    {
        $sel = new EligibilitySelector;
        $result = $sel->select(
            [['agent_id' => 1, 'online' => true, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 5]],
            [[
                'session_id' => 1,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'platform' => 'facebook',
                'account' => ['platform' => 'facebook', 'declared_email' => 'a@b.com', 'verified' => false],
            ]],
            ['platform' => 'facebook', 'target_count' => 1]
        );
        self::assertSame(0, $result['dispatched_count']);
        self::assertContains('identity_incomplete', $result['skip_reasons']);
    }

    public function test_session_lock_respects_capacity(): void
    {
        $p = new SessionLockPolicy;
        self::assertTrue($p->agentHasCapacity([['status' => 'running']], 2));
        self::assertFalse($p->agentHasCapacity([['status' => 'running'], ['status' => 'claimed']], 2));
        self::assertSame(2, $p->countActiveJobs([['status' => 'running'], ['status' => 'unknown'], ['status' => 'completed']]));
    }

    public function test_job_state_machine_and_lease(): void
    {
        $sm = new JobStateMachine;
        self::assertTrue($sm->canTransition(JobStatus::Reserved, JobStatus::Claimed));
        self::assertFalse($sm->canTransition(JobStatus::Completed, JobStatus::Running));
        self::assertSame(JobStatus::Unknown, $sm->onLeaseExpired(JobStatus::Running));
        self::assertSame(JobStatus::Pending, $sm->onLeaseExpired(JobStatus::Reserved));
        self::assertTrue($sm->canTransition(JobStatus::Unknown, JobStatus::Claimed));
        self::assertFalse($sm->canTransition(JobStatus::Unknown, JobStatus::Completed));
        self::assertTrue($sm->canTransition(JobStatus::Unknown, JobStatus::HumanRequired));
    }

    public function test_session_lock_and_agent_active(): void
    {
        $p = new SessionLockPolicy;
        $now = new \DateTimeImmutable('2026-10-21T12:00:00Z');
        self::assertTrue($p->canLock(['locked_job_id' => null], 1, $now));
        self::assertFalse($p->canLock([
            'locked_job_id' => 9,
            'lock_expires_at' => new \DateTimeImmutable('2026-10-21T12:05:00Z'),
        ], 1, $now));
        self::assertTrue($p->canLock([
            'locked_job_id' => 9,
            'lock_expires_at' => new \DateTimeImmutable('2026-10-21T11:00:00Z'),
        ], 1, $now));
        self::assertTrue($p->agentHasActiveJob([['status' => 'running']]));
        self::assertFalse($p->agentHasActiveJob([['status' => 'completed']]));
    }

    public function test_cron_skip_offline_and_partial(): void
    {
        $p = new CronSkipPolicy;
        $offline = $p->whenAllAgentsOffline([['online' => false], ['online' => false]]);
        self::assertFalse($offline['should_dispatch']);
        self::assertSame(CronSkipPolicy::REASON_OFFLINE, $offline['reason']);

        $summary = $p->summarizeDispatch(10, 6);
        self::assertSame(6, $summary['dispatched_count']);
        self::assertSame(4, $summary['shortfall']);
        self::assertFalse($summary['catch_up']);
        self::assertSame(CronSkipPolicy::REASON_PARTIAL, $summary['outcome']);
    }

    public function test_workflow_output_mapping(): void
    {
        $m = new WorkflowOutputMapper;
        $built = $m->buildRunnerPayload(
            [
                ['widget_key' => 'post', 'input' => ['text' => 'hi']],
                ['widget_key' => 'comment', 'output_bindings' => ['url' => 'post.post_url']],
            ],
            ['campaign' => 'x'],
            ['post' => ['post_url' => 'https://example.com/p/1']]
        );
        self::assertTrue($built['ok']);
        self::assertSame('https://example.com/p/1', $built['steps'][1]['input']['url']);
        self::assertSame('x', $built['steps'][0]['input']['campaign']);
    }

    public function test_artifact_policy_rejects_secrets_and_bad_mime(): void
    {
        $p = new ArtifactAcceptPolicy;
        $bad = $p->validate('application/x-msdownload', 100, []);
        self::assertFalse($bad['ok']);
        $secret = $p->validate('image/png', 100, ['cookie' => 'x']);
        self::assertFalse($secret['ok']);
        $ok = $p->validate('image/png', 100, ['note' => 'ok']);
        self::assertTrue($ok['ok']);
    }
}
