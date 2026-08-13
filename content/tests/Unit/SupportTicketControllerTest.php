<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SupportTicketControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_saves_local_when_remote_disabled(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            $this->markTestSkipped('support_tickets table is not available.');
        }

        config([
            'services.support_ticket.enabled' => false,
            'services.support_ticket.endpoint' => null,
        ]);

        $owner = User::query()->create([
            'name' => 'Ticket Owner',
            'email' => 'ticket-owner@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $response = $this->actingAs($owner)->postJson('/api/seo/support-tickets', [
            'title' => 'Lỗi publish',
            'body' => 'Queue treo',
            'page_url' => 'https://example.test/seo/abc/keywords?token=secret',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('ticket.status', SupportTicket::STATUS_QUEUED);
        $response->assertJsonFragment(['success' => true]);
        self::assertStringContainsString('cục bộ', (string) $response->json('message'));

        $ticket = SupportTicket::query()->first();
        self::assertNotNull($ticket);
        self::assertSame('Lỗi publish', $ticket->title);
        self::assertSame(SupportTicket::STATUS_QUEUED, $ticket->status);
        $metaUrl = (string) (($ticket->metadata['page_url'] ?? ''));
        self::assertStringNotContainsString('token=secret', $metaUrl);
    }

    public function test_store_keeps_local_when_remote_throws(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            $this->markTestSkipped('support_tickets table is not available.');
        }

        config([
            'services.support_ticket.enabled' => true,
            'services.support_ticket.endpoint' => 'https://support.example.test/tickets',
            'services.support_ticket.timeout' => 2,
        ]);

        Http::fake([
            'support.example.test/*' => Http::response('upstream down', 503),
        ]);

        $owner = User::query()->create([
            'name' => 'Ticket Owner 2',
            'email' => 'ticket-owner-2@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $response = $this->actingAs($owner)->postJson('/api/seo/support-tickets', [
            'title' => 'Offline remote',
            'body' => 'Should not 500',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('ticket.status', SupportTicket::STATUS_FAILED);
        self::assertSame(1, SupportTicket::query()->count());
        self::assertNotNull(SupportTicket::query()->first()?->last_error);
    }
}
