<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Illuminate\Notifications\DatabaseNotification;
use Omnichannel\Addons\Seo\Enums\NotificationDisplaySurface;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalAlertHookService;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;
use Omnichannel\Addons\Seo\Services\Notifications\Publishers\SemanticServiceNotificationPublisher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SemanticServiceNotificationPublisherTest extends TestCase
{
    public function test_publisher_uses_operational_alert_hook_and_stable_dedup(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SemanticServiceNotificationPublisher::class))->getFileName()
        );
        self::assertStringContainsString('NotificationDisplaySurface::withOperationalAlertHook()', $source);
        self::assertStringContainsString("DEDUP_KEY = 'semantic-service:availability'", $source);
        self::assertStringContainsString('SemanticServiceUnavailable', $source);
        self::assertStringContainsString('SemanticServiceDegraded', $source);
        self::assertStringContainsString('SemanticServiceRecovered', $source);
        self::assertStringContainsString('không dùng fallback heuristic', $source);
    }

    public function test_event_codes_are_registered(): void
    {
        $codes = OperationalNotificationEventCode::values();
        self::assertContains('semantic.service_unavailable', $codes);
        self::assertContains('semantic.service_degraded', $codes);
        self::assertContains('semantic.service_recovered', $codes);
    }

    public function test_operational_alert_hook_includes_active_semantic_alert(): void
    {
        $alerts = $this->hook()->fromActiveRows([
            $this->row(
                id: 'sem1',
                title: 'Semantic Service không hoạt động',
                body: 'down',
                severity: NotificationSeverity::Critical,
                eventCode: OperationalNotificationEventCode::SemanticServiceUnavailable->value,
                dedupKey: SemanticServiceNotificationPublisher::DEDUP_KEY,
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
            ),
        ]);
        self::assertCount(1, $alerts);
        self::assertSame('Semantic Service không hoạt động', $alerts[0]->title);
    }

    public function test_resolved_semantic_alert_not_returned(): void
    {
        $alerts = $this->hook()->fromActiveRows([
            $this->row(
                id: 'sem2',
                title: 'Semantic Service không hoạt động',
                body: 'down',
                severity: NotificationSeverity::Critical,
                eventCode: OperationalNotificationEventCode::SemanticServiceUnavailable->value,
                dedupKey: SemanticServiceNotificationPublisher::DEDUP_KEY,
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
                resolvedAt: '2026-10-07T12:00:00+00:00',
            ),
        ]);
        self::assertSame([], $alerts);
    }

    private function hook(): OperationalAlertHookService
    {
        $notifications = (new ReflectionClass(OperationalNotificationService::class))
            ->newInstanceWithoutConstructor();

        return new OperationalAlertHookService($notifications);
    }

    /**
     * @param  list<string>  $surfaces
     */
    private function row(
        string $id,
        string $title,
        string $body,
        NotificationSeverity $severity,
        string $eventCode,
        string $dedupKey,
        array $surfaces,
        ?string $resolvedAt = null,
    ): DatabaseNotification {
        $row = new DatabaseNotification;
        $row->forceFill([
            'id' => $id,
            'type' => 'filament',
            'event_code' => $eventCode,
            'severity' => $severity->value,
            'dedup_key' => $dedupKey,
            'occurrence_count' => 1,
            'resolved_at' => $resolvedAt,
            'last_occurred_at' => '2026-10-07T11:00:00+00:00',
            'data' => [
                'title' => $title,
                'body' => $body,
                'actions' => [],
                'operational' => [
                    'event_code' => $eventCode,
                    'severity' => $severity->value,
                    'occurrence_count' => 1,
                    'display_surfaces' => $surfaces,
                    'context' => [],
                ],
            ],
        ]);

        return $row;
    }
}
