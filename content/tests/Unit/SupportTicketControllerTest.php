<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SupportTicketControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_to_core_db_without_connection_hash(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            $this->markTestSkipped('support_tickets table is not available.');
        }

        $owner = User::query()->create([
            'name' => 'Ticket Owner',
            'email' => 'ticket-owner@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $response = $this->actingAs($owner)->postJson('/api/support-tickets', [
            'title' => 'Lỗi publish',
            'body' => 'Queue treo',
            'page_url' => 'https://example.test/admin?token=secret',
            'route_name' => 'filament.admin.pages.dashboard',
            'service' => 'admin',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Đã gửi ticket thành công.');
        $response->assertJsonPath('ticket.status', SupportTicket::STATUS_QUEUED);
        self::assertStringNotContainsString('cục bộ', (string) $response->json('message'));

        $ticket = SupportTicket::query()->first();
        self::assertNotNull($ticket);
        self::assertSame('Lỗi publish', $ticket->title);
        self::assertSame('Queue treo', $ticket->body);
        self::assertSame((int) $owner->id, (int) $ticket->user_id);
        self::assertNull($ticket->connection_hash);
        self::assertSame('admin', $ticket->metadata['service'] ?? null);
        $metaUrl = (string) (($ticket->metadata['page_url'] ?? ''));
        self::assertStringNotContainsString('token=secret', $metaUrl);
        self::assertSame(
            (string) config('database.core_connection', 'mysql'),
            (string) $ticket->getConnectionName(),
        );
    }

    public function test_store_persists_attachment_metadata(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            $this->markTestSkipped('support_tickets table is not available.');
        }

        $owner = User::query()->create([
            'name' => 'Ticket Attach Owner',
            'email' => 'ticket-attach@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $file = UploadedFile::fake()->image('screenshot.png', 40, 40);

        $response = $this->actingAs($owner)->post('/api/support-tickets', [
            'title' => 'Có ảnh',
            'body' => 'Paste/upload screenshot',
            'service' => 'seo',
            'files' => [$file],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated();
        $ticket = SupportTicket::query()->latest('id')->first();
        self::assertNotNull($ticket);
        $attachments = $ticket->metadata['attachments'] ?? null;
        self::assertIsArray($attachments);
        self::assertNotEmpty($attachments);
        self::assertTrue((bool) ($attachments[0]['is_image'] ?? false));
        self::assertNotEmpty((string) ($attachments[0]['url'] ?? ''));
    }

    public function test_seo_compat_route_delegates_to_same_global_store(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            $this->markTestSkipped('support_tickets table is not available.');
        }

        $owner = User::query()->create([
            'name' => 'Ticket Compat',
            'email' => 'ticket-compat@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $response = $this->actingAs($owner)->postJson('/api/seo/support-tickets', [
            'title' => 'Compat',
            'body' => 'Same action',
            'service' => 'seo',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'Đã gửi ticket thành công.');
        self::assertSame(1, SupportTicket::query()->count());
    }
}
