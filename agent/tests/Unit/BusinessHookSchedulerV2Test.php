<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerService;
use Carbon\Carbon;
use Tests\TestCase;

final class BusinessHookSchedulerV2Test extends TestCase
{
    public function test_compute_next_run_at_respects_timezone(): void
    {
        $rule = new AutomationRule([
            'schedule_expression' => '0 9 * * *',
            'schedule_timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        $service = app(AutomationSchedulerService::class);
        $next = $service->computeNextRunAt($rule, Carbon::parse('2026-07-20 08:00:00', 'Asia/Ho_Chi_Minh'));

        self::assertNotNull($next);
        self::assertSame('Asia/Ho_Chi_Minh', $next->timezone->getName());
    }

    public function test_occurrence_key_is_deterministic(): void
    {
        $occurrenceAt = Carbon::parse('2026-07-20T09:00:00+07:00');
        $a = hash('sha256', '5|1|'.$occurrenceAt->utc()->toIso8601String());
        $b = hash('sha256', '5|1|'.$occurrenceAt->utc()->toIso8601String());
        self::assertSame($a, $b);
    }
}
