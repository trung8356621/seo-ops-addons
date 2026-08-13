<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\Models\SupportTicket;
use Omnichannel\Addons\Seo\Services\SupportTicketDeliveryService;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

final class SupportTicketOfflineContractTest extends TestCase
{
    public function test_delivery_service_reports_disabled_without_http(): void
    {
        config([
            'services.support_ticket.enabled' => false,
            'services.support_ticket.endpoint' => 'https://example.test/t',
        ]);

        $service = new SupportTicketDeliveryService();
        self::assertFalse($service->isRemoteEnabled());

        $ticket = new SupportTicket([
            'title' => 'x',
            'body' => 'y',
        ]);
        $result = $service->attemptDelivery($ticket);
        self::assertFalse($result['ok']);
        self::assertSame('remote_disabled', $result['error']);
    }

    public function test_controller_never_returns_500_path_on_remote_fail_via_source(): void
    {
        $path = (new ReflectionClass(\Omnichannel\Addons\Seo\Http\Controllers\SupportTicketController::class))->getFileName();
        $source = (string) file_get_contents((string) $path);

        self::assertStringContainsString('STATUS_QUEUED', $source);
        self::assertStringContainsString('Đã lưu cục bộ', $source);
        self::assertStringContainsString('deliverOrKeepQueued', $source);
        self::assertStringNotContainsString('abort(500', $source);
        self::assertStringContainsString(', 201)', $source);
    }

    public function test_delivery_service_catches_http_failures(): void
    {
        config([
            'services.support_ticket.enabled' => true,
            'services.support_ticket.endpoint' => 'https://support.example.test/tickets',
            'services.support_ticket.timeout' => 2,
        ]);

        Http::fake([
            'support.example.test/*' => Http::response('nope', 503),
        ]);

        $ticket = new SupportTicket([
            'id' => 1,
            'title' => 't',
            'body' => 'b',
        ]);
        $result = (new SupportTicketDeliveryService())->attemptDelivery($ticket);
        self::assertFalse($result['ok']);
        self::assertNotNull($result['error']);
    }
}
