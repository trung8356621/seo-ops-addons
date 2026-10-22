<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Artifacts\ArtifactAcceptPolicy;
use Omnichannel\Addons\SocialAutomation\Scheduling\CronSkipPolicy;
use PHPUnit\Framework\TestCase;

final class ArtifactAndCronPolicyTest extends TestCase
{
    public function test_screenshot_success_and_failure_metadata(): void
    {
        $p = new ArtifactAcceptPolicy;
        $ok = $p->validateScreenshotMetadata('success', ['phase' => 'final', 'note' => 'done']);
        self::assertTrue($ok['ok']);
        self::assertSame('final', $ok['phase']);

        $fail = $p->validateScreenshotMetadata('failed', ['step' => 'submit']);
        self::assertTrue($fail['ok']);
        self::assertSame('submit', $fail['phase']);

        $missing = $p->validateScreenshotMetadata('failed', []);
        self::assertFalse($missing['ok']);
        self::assertContains('failure_step_required', $missing['errors']);
    }

    public function test_duplicate_checksum_detection(): void
    {
        $p = new ArtifactAcceptPolicy;
        $hash = str_repeat('ab', 32);
        self::assertTrue($p->isDuplicateChecksum($hash, $hash));
        self::assertFalse($p->isDuplicateChecksum($hash, str_repeat('cd', 32)));
        self::assertFalse($p->isDuplicateChecksum(null, $hash));
    }

    public function test_cron_idempotency_key_stable_for_window(): void
    {
        $p = new CronSkipPolicy;
        $a = $p->triggerIdempotencyKey('sched-1', '2026-10-22T10:00:00+00:00');
        $b = $p->triggerIdempotencyKey('sched-1', '2026-10-22T10:00:00+00:00');
        $c = $p->triggerIdempotencyKey('sched-1', '2026-10-22T10:05:00+00:00');
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
    }

    public function test_offline_skip_no_dispatch(): void
    {
        $p = new CronSkipPolicy;
        $r = $p->whenAllAgentsOffline([['online' => false]]);
        self::assertFalse($r['should_dispatch']);
        self::assertSame(CronSkipPolicy::REASON_OFFLINE, $r['reason']);
    }
}
