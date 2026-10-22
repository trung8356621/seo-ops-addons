<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\JobIdempotencyKey;
use Omnichannel\Addons\SocialAutomation\Scheduling\CronSkipPolicy;
use PHPUnit\Framework\TestCase;

final class EligibilityDistributionTest extends TestCase
{
    private EligibilitySelector $selector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selector = new EligibilitySelector;
    }

    /** @return array<string, mixed> */
    private function account(string $id): array
    {
        return [
            'platform' => 'facebook',
            'platform_account_id' => $id,
            'verified' => true,
            'identity_source' => 'platform_id',
        ];
    }

    public function test_target_ten_with_ten_distinct_identities(): void
    {
        $agents = [];
        $sessions = [];
        for ($i = 1; $i <= 10; $i++) {
            $agents[] = [
                'agent_id' => $i,
                'online' => true,
                'availability' => 'available',
                'active_jobs' => 0,
                'max_concurrency' => 1,
            ];
            $sessions[] = [
                'session_id' => 100 + $i,
                'agent_id' => $i,
                'ready_status' => 'ready',
                'busy' => false,
                'platform' => 'facebook',
                'account' => $this->account('fb-'.$i),
            ];
        }

        $result = $this->selector->select($agents, $sessions, [
            'platform' => 'facebook',
            'target_count' => 10,
        ]);

        self::assertSame(10, $result['dispatched_count']);
        self::assertSame(0, $result['shortfall']);
    }

    public function test_ten_sessions_five_unique_accounts(): void
    {
        $agents = [['agent_id' => 1, 'online' => true, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 10]];
        $sessions = [];
        for ($i = 1; $i <= 10; $i++) {
            // Only 5 distinct platform account ids across 10 sessions.
            $sessions[] = [
                'session_id' => $i,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'platform' => 'facebook',
                'account' => $this->account('dup-'.(($i % 5) + 1)),
            ];
        }

        $result = $this->selector->select($agents, $sessions, [
            'platform' => 'facebook',
            'target_count' => 10,
        ]);

        self::assertSame(5, $result['dispatched_count']);
        self::assertSame(5, $result['shortfall']);
    }

    public function test_one_agent_many_accounts_uses_concurrency(): void
    {
        $agents = [['agent_id' => 1, 'online' => true, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 3]];
        $sessions = [];
        for ($i = 1; $i <= 5; $i++) {
            $sessions[] = [
                'session_id' => $i,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'platform' => 'facebook',
                'account' => $this->account('multi-'.$i),
            ];
        }

        $result = $this->selector->select($agents, $sessions, [
            'platform' => 'facebook',
            'target_count' => 5,
        ]);

        self::assertSame(3, $result['dispatched_count']);
        self::assertSame(2, $result['shortfall']);
    }

    public function test_same_account_across_agents_deduped(): void
    {
        $agents = [
            ['agent_id' => 1, 'online' => true, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 1],
            ['agent_id' => 2, 'online' => true, 'availability' => 'available', 'active_jobs' => 0, 'max_concurrency' => 1],
        ];
        $sessions = [
            [
                'session_id' => 1,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'platform' => 'facebook',
                'account' => $this->account('same'),
            ],
            [
                'session_id' => 2,
                'agent_id' => 2,
                'ready_status' => 'ready',
                'busy' => false,
                'platform' => 'facebook',
                'account' => $this->account('same'),
            ],
        ];

        $result = $this->selector->select($agents, $sessions, [
            'platform' => 'facebook',
            'target_count' => 2,
        ]);

        self::assertSame(1, $result['dispatched_count']);
        self::assertSame(1, $result['shortfall']);
    }

    public function test_disabled_agent_skipped(): void
    {
        $result = $this->selector->select(
            [['agent_id' => 1, 'online' => true, 'availability' => 'disabled', 'active_jobs' => 0, 'max_concurrency' => 1]],
            [[
                'session_id' => 1,
                'agent_id' => 1,
                'ready_status' => 'ready',
                'busy' => false,
                'platform' => 'facebook',
                'account' => $this->account('x'),
            ]],
            ['platform' => 'facebook', 'target_count' => 1]
        );
        self::assertSame(0, $result['dispatched_count']);
        self::assertContains('agent_unavailable', $result['skip_reasons']);
    }

    public function test_shortfall_never_catch_up(): void
    {
        $summary = (new CronSkipPolicy)->summarizeDispatch(10, 4);
        self::assertSame(6, $summary['shortfall']);
        self::assertFalse($summary['catch_up']);
        self::assertSame(CronSkipPolicy::REASON_PARTIAL, $summary['outcome']);
    }

    public function test_job_idempotency_key_stable(): void
    {
        $a = JobIdempotencyKey::forDispatch('task-1', 9, null, 42);
        $b = JobIdempotencyKey::forDispatch('task-1', 9, null, 42);
        $c = JobIdempotencyKey::forDispatch('task-1', 9, 7, 42);
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertSame(64, strlen($a));
    }
}
