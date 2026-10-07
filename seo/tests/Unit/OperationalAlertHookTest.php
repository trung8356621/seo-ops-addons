<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Core\ClientCoreServiceProvider;
use Illuminate\Notifications\DatabaseNotification;
use Omnichannel\Addons\Seo\Enums\NotificationDisplaySurface;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalAlertHookService;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;
use Omnichannel\Addons\Seo\Services\Notifications\Publishers\SiteHealthNotificationPublisher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;
use Tests\Support\ResolvesMovedAddonPaths;

final class OperationalAlertHookTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    public function test_ordinary_notification_not_returned_by_hook(): void
    {
        $alerts = $this->hook()->fromActiveRows([
            $this->row(
                id: 'n1',
                title: 'Batch failed',
                body: 'Partial failure',
                severity: NotificationSeverity::Warning,
                eventCode: OperationalNotificationEventCode::GenerationBatchFailed->value,
                dedupKey: 'generation-batch:1:1:1',
                surfaces: [NotificationDisplaySurface::NotificationCenter->value],
            ),
        ]);

        self::assertSame([], $alerts);
    }

    public function test_promoted_notification_is_returned_by_hook(): void
    {
        $alerts = $this->hook()->fromActiveRows([
            $this->row(
                id: 'n2',
                title: 'Runner down',
                body: 'Worker unavailable',
                severity: NotificationSeverity::Critical,
                eventCode: OperationalNotificationEventCode::RunnerUnhealthy->value,
                dedupKey: 'runner-health:1:critical',
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
                occurrenceCount: 1,
            ),
        ]);

        self::assertCount(1, $alerts);
        self::assertSame('Runner down', $alerts[0]->title);
        self::assertSame(NotificationSeverity::Critical, $alerts[0]->severity);
        self::assertSame(1, $alerts[0]->occurrenceCount);
    }

    public function test_resolved_notification_is_not_returned(): void
    {
        $alerts = $this->hook()->fromActiveRows([
            $this->row(
                id: 'n3',
                title: 'Runner down',
                body: 'Worker unavailable',
                severity: NotificationSeverity::Critical,
                eventCode: OperationalNotificationEventCode::RunnerUnhealthy->value,
                dedupKey: 'runner-health:2:critical',
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
                resolvedAt: '2026-10-07T00:00:00+00:00',
            ),
        ]);

        self::assertSame([], $alerts);
    }

    public function test_dedup_update_keeps_one_alert_with_latest_occurrence(): void
    {
        $alerts = $this->hook()->fromActiveRows([
            $this->row(
                id: 'n4',
                title: 'Runner down',
                body: '[runner] Second · 2 lần',
                severity: NotificationSeverity::Critical,
                eventCode: OperationalNotificationEventCode::RunnerUnhealthy->value,
                dedupKey: 'runner-health:3:critical',
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
                occurrenceCount: 2,
            ),
        ]);

        self::assertCount(1, $alerts);
        self::assertSame(2, $alerts[0]->occurrenceCount);
        self::assertStringContainsString('Second', $alerts[0]->message);
    }

    public function test_site_health_down_and_degraded_are_promoted(): void
    {
        $publisher = $this->readLegacyOrMovedAddonFile(
            'Services/Notifications/Publishers/SiteHealthNotificationPublisher.php'
        );
        self::assertStringContainsString('NotificationDisplaySurface::withOperationalAlertHook()', $publisher);
        self::assertStringContainsString('SiteHealthDown', $publisher);
        self::assertStringContainsString('SiteHealthDegraded', $publisher);

        $alerts = $this->hook()->fromActiveRows([
            $this->row(
                id: 'n5',
                title: 'Site degraded',
                body: 'degraded',
                severity: NotificationSeverity::Warning,
                eventCode: OperationalNotificationEventCode::SiteHealthDegraded->value,
                dedupKey: 'site-health:incident:12',
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
                lastOccurredAt: '2026-10-07T01:00:00+00:00',
            ),
            $this->row(
                id: 'n6',
                title: 'Site down',
                body: 'down',
                severity: NotificationSeverity::Critical,
                eventCode: OperationalNotificationEventCode::SiteHealthDown->value,
                dedupKey: 'site-health:incident:11',
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
                lastOccurredAt: '2026-10-07T00:00:00+00:00',
            ),
        ]);

        self::assertCount(2, $alerts);
        self::assertSame('Site down', $alerts[0]->title);
        self::assertSame('Site degraded', $alerts[1]->title);
    }

    public function test_site_health_recovered_resolved_removed_from_hook(): void
    {
        $alerts = $this->hook()->fromActiveRows([
            $this->row(
                id: 'n7',
                title: 'Site down',
                body: 'DNS failed',
                severity: NotificationSeverity::Critical,
                eventCode: OperationalNotificationEventCode::SiteHealthDown->value,
                dedupKey: 'site-health:incident:99',
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
                resolvedAt: '2026-10-07T02:00:00+00:00',
            ),
            $this->row(
                id: 'n8',
                title: 'Site recovered',
                body: 'OK',
                severity: NotificationSeverity::Info,
                eventCode: OperationalNotificationEventCode::SiteHealthRecovered->value,
                dedupKey: 'recovery:site-health:incident:99',
                surfaces: [NotificationDisplaySurface::NotificationCenter->value],
            ),
        ]);

        self::assertSame([], $alerts);
    }

    public function test_current_user_isolation_is_query_scoped_to_notifiable(): void
    {
        $service = $this->readLegacyOrMovedAddonFile(
            'Services/Notifications/OperationalAlertHookService.php'
        );
        self::assertStringContainsString('function forUser(User $user)', $service);
        self::assertStringContainsString('$user->notifications()', $service);
        self::assertStringContainsString('whereNull(\'resolved_at\')', $service);
    }

    public function test_severity_ordering(): void
    {
        $alerts = $this->hook()->fromActiveRows([
            $this->row(
                id: 'n9',
                title: 'Warn',
                body: 'w',
                severity: NotificationSeverity::Warning,
                eventCode: OperationalNotificationEventCode::RunnerUnhealthy->value,
                dedupKey: 'order:warn',
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
                lastOccurredAt: '2026-10-07T03:00:00+00:00',
            ),
            $this->row(
                id: 'n10',
                title: 'Crit',
                body: 'c',
                severity: NotificationSeverity::Critical,
                eventCode: OperationalNotificationEventCode::SiteHealthDown->value,
                dedupKey: 'order:crit',
                surfaces: NotificationDisplaySurface::normalize(
                    NotificationDisplaySurface::withOperationalAlertHook()
                ),
                lastOccurredAt: '2026-10-07T01:00:00+00:00',
            ),
        ]);

        self::assertSame(['Crit', 'Warn'], array_map(static fn ($a): string => $a->title, $alerts));
    }

    public function test_shared_hook_rendered_once_not_copied_into_pages(): void
    {
        $core = (string) file_get_contents((new ReflectionClass(ClientCoreServiceProvider::class))->getFileName());
        self::assertStringContainsString('registerOperationalAlertHook', $core);
        self::assertSame(1, substr_count($core, "view('filament.hooks.operational-alert-hook')"));
        self::assertStringNotContainsString('registerGlobalSiteHealthHook', $core);

        $blade = (string) file_get_contents(ProjectRoot::path().'/resources/views/filament/hooks/operational-alert-hook.blade.php');
        self::assertStringContainsString("@livewire('operational-alert-hook')", $blade);

        $livewireView = $this->readLegacyOrMovedAddonFile('resources/views/livewire/operational-alert-hook.blade.php');
        self::assertStringContainsString('data-operational-alert-hook', $livewireView);

        $service = $this->readLegacyOrMovedAddonFile('Services/Notifications/OperationalNotificationService.php');
        self::assertStringContainsString('displaySurfaces', $service);
        self::assertStringContainsString('display_surfaces', $service);

        self::assertTrue(enum_exists(NotificationDisplaySurface::class));
        self::assertSame('operational_alert', NotificationDisplaySurface::OperationalAlert->value);
        self::assertSame('operational-alert', NotificationDisplaySurface::HOOK_ID);
        self::assertTrue(class_exists(SiteHealthNotificationPublisher::class));
        self::assertTrue(class_exists(OperationalNotificationService::class));
        self::assertTrue(class_exists(
            \Omnichannel\Addons\Seo\Services\Notifications\Publishers\SemanticServiceNotificationPublisher::class
        ));
        $semanticPublisher = $this->readLegacyOrMovedAddonFile(
            'Services/Notifications/Publishers/SemanticServiceNotificationPublisher.php'
        );
        self::assertStringContainsString('NotificationDisplaySurface::withOperationalAlertHook()', $semanticPublisher);
    }

    public function test_display_surface_normalize_always_includes_notification_center(): void
    {
        self::assertSame(
            ['notification_center', 'operational_alert'],
            NotificationDisplaySurface::normalize([NotificationDisplaySurface::OperationalAlert]),
        );
        self::assertSame(
            ['notification_center'],
            NotificationDisplaySurface::normalize([]),
        );
        self::assertFalse(
            NotificationDisplaySurface::includesOperationalAlertHook(
                NotificationDisplaySurface::normalize([])
            )
        );
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
        int $occurrenceCount = 1,
        ?string $resolvedAt = null,
        ?string $lastOccurredAt = null,
        ?string $actionUrl = null,
    ): DatabaseNotification {
        $row = new DatabaseNotification;
        $row->forceFill([
            'id' => $id,
            'type' => 'filament',
            'event_code' => $eventCode,
            'severity' => $severity->value,
            'dedup_key' => $dedupKey,
            'occurrence_count' => $occurrenceCount,
            'resolved_at' => $resolvedAt,
            'last_occurred_at' => $lastOccurredAt,
            'data' => [
                'title' => $title,
                'body' => $body,
                'actions' => $actionUrl !== null ? [['label' => 'Mở', 'url' => $actionUrl]] : [],
                'operational' => [
                    'event_code' => $eventCode,
                    'severity' => $severity->value,
                    'occurrence_count' => $occurrenceCount,
                    'display_surfaces' => $surfaces,
                    'context' => [],
                ],
            ],
        ]);

        return $row;
    }
}
