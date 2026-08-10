<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectAutoScheduleService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\PublishingSchedulePlan;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PublishingSchedulePlanContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SystemDateTime::useConfig([
            'timezone' => 'Asia/Ho_Chi_Minh',
            'preset' => 'vi',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:30:00', 'Asia/Ho_Chi_Minh'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        SystemDateTime::useConfig(null);
        parent::tearDown();
    }

    public function test_in_day_slots_are_unique_and_interval_correct(): void
    {
        $service = (new \ReflectionClass(ContentProjectAutoScheduleService::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(ContentProjectAutoScheduleService::class, 'buildInDaySlots');
        $method->setAccessible(true);

        /** @var list<Carbon> $slots */
        $slots = $method->invoke($service, 4, 10, 'Asia/Ho_Chi_Minh');

        self::assertCount(4, $slots);
        $isos = array_map(static fn (Carbon $c): string => $c->copy()->utc()->toIso8601String(), $slots);
        self::assertSame(count($isos), count(array_unique($isos)));

        // 14:30 + 5 safety = 14:35 local â†’ 07:35Z; then +10m
        self::assertSame('2026-08-03T07:35:00+00:00', $isos[0]);
        self::assertSame('2026-08-03T07:45:00+00:00', $isos[1]);
        self::assertSame('2026-08-03T07:55:00+00:00', $isos[2]);
        self::assertSame('2026-08-03T08:05:00+00:00', $isos[3]);
    }

    public function test_schedule_plan_builds_per_item_map(): void
    {
        $slots = [
            Carbon::parse('2026-08-03T07:35:00Z'),
            Carbon::parse('2026-08-03T07:45:00Z'),
            Carbon::parse('2026-08-03T07:55:00Z'),
        ];
        $plan = PublishingSchedulePlan::fromSlots([11, 22, 33], $slots, [], 'Asia/Ho_Chi_Minh');

        self::assertSame([
            11 => '2026-08-03T07:35:00+00:00',
            22 => '2026-08-03T07:45:00+00:00',
            33 => '2026-08-03T07:55:00+00:00',
        ], $plan->itemScheduleMap);
        self::assertCount(3, array_unique(array_values($plan->itemScheduleMap)));

        $arr = $plan->toArray();
        self::assertArrayHasKey('item_schedule_map', $arr);
        self::assertSame($plan->slots, $arr['slots']);
    }

    public function test_system_display_of_plan_slots(): void
    {
        $display = SystemDateTime::formatScheduleParts('2026-08-03T07:35:00Z');

        self::assertSame('03/08/2026', $display['date']);
        self::assertSame('14:35', $display['time']);
    }

    public function test_pending_ux_is_presentation_only_in_trait_source(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithContentProjectPublishingActions.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('pendingPhase', $src);
        self::assertStringContainsString('pendingTaskIds', $src);
        self::assertStringContainsString("'accepted'", $src);
        self::assertStringNotContainsString("publish_queue_status' => 'updating'", $src);
        self::assertStringContainsString('KhÃ´ng success toast', $src);
    }

    public function test_schedule_plan_service_uses_schedule_plan_persist(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectAutoScheduleService.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('PublishingSchedulePlan', $src);
        self::assertStringContainsString('schedulePlan', $src);
        self::assertStringContainsString('SAFETY_DELAY_MINUTES = 5', $src);
        self::assertStringContainsString('buildPlan', $src);
    }

    public function test_queue_service_has_schedule_plan_not_bulk_same_timestamp_helper_name(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectPublishingQueueService.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('function schedulePlan', $src);
        self::assertStringContainsString('Never bulk-set one timestamp for all ids', $src);
    }
}
