<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers;

use Omnichannel\Addons\Content\Services\TeamChatAttachmentService;
use Omnichannel\Addons\Seo\Services\SupportTicketDeliveryService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketDeliveryService $delivery,
        private readonly TeamChatAttachmentService $attachments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) auth()->id();
        if ($userId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $rows = SupportTicket::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (SupportTicket $ticket): array => $this->serialize($ticket))
            ->values()
            ->all();

        return response()->json([
            'tickets' => $rows,
            'remote_enabled' => $this->delivery->isRemoteEnabled(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = (int) auth()->id();
        if ($userId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'page_url' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file'],
        ]);

        $connectionHash = SeoConnectionContext::resolveHashFromRequest()
            ?? SeoConnectionContext::hash();
        if (! is_string($connectionHash) || ! SeoConnectionContext::isValidHashFormat($connectionHash)) {
            $connectionHash = null;
        }

        $ownerId = SeoAccessControl::accountOwnerId() ?? $userId;
        $storedAttachments = $this->storeUploadedAttachments($request, $ownerId);
        if ($storedAttachments instanceof JsonResponse) {
            return $storedAttachments;
        }

        $metadata = $this->safeMetadata($request, $validated['page_url'] ?? null, $connectionHash);
        if ($storedAttachments !== []) {
            $metadata['attachments'] = $storedAttachments;
        }

        $ticket = SupportTicket::query()->create([
            'user_id' => $userId,
            'connection_hash' => $connectionHash,
            'title' => trim($validated['title']),
            'body' => trim($validated['body']),
            'status' => SupportTicket::STATUS_QUEUED,
            'metadata' => $metadata,
        ]);

        $deliveryNote = $this->deliverOrKeepQueued($ticket);

        return response()->json([
            'success' => true,
            'ticket' => $this->serialize($ticket->fresh() ?? $ticket),
            'message' => $deliveryNote,
            'remote_enabled' => $this->delivery->isRemoteEnabled(),
        ], 201);
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        $userId = (int) auth()->id();
        if ($userId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $ticket = SupportTicket::query()
            ->where('user_id', $userId)
            ->whereKey($id)
            ->first();

        if ($ticket === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($ticket->status === SupportTicket::STATUS_SENT) {
            return response()->json([
                'success' => true,
                'ticket' => $this->serialize($ticket),
                'message' => 'Ticket đã gửi thành công trước đó.',
            ]);
        }

        $ticket->status = SupportTicket::STATUS_QUEUED;
        $ticket->last_error = null;
        $ticket->save();

        $note = $this->deliverOrKeepQueued($ticket);

        return response()->json([
            'success' => true,
            'ticket' => $this->serialize($ticket->fresh() ?? $ticket),
            'message' => $note,
            'remote_enabled' => $this->delivery->isRemoteEnabled(),
        ]);
    }

    private function deliverOrKeepQueued(SupportTicket $ticket): string
    {
        if (! $this->delivery->isRemoteEnabled()) {
            $ticket->status = SupportTicket::STATUS_QUEUED;
            $ticket->last_error = null;
            $ticket->save();

            return 'Đã lưu cục bộ. Máy chủ hỗ trợ hiện chưa sẵn sàng.';
        }

        $result = $this->delivery->attemptDelivery($ticket);
        if ($result['ok']) {
            $ticket->status = SupportTicket::STATUS_SENT;
            $ticket->remote_ticket_id = $result['remote_ticket_id'];
            $ticket->last_error = null;
            $ticket->sent_at = now();
            $ticket->save();

            return 'Đã gửi ticket tới máy chủ hỗ trợ.';
        }

        $ticket->status = SupportTicket::STATUS_FAILED;
        $ticket->last_error = $result['error'] ?? 'remote_failed';
        $ticket->save();

        return 'Đã lưu cục bộ. Gửi tới máy chủ hỗ trợ thất bại — bạn có thể Retry sau.';
    }

    /**
     * Reuse TeamChatAttachmentService — no second upload stack.
     *
     * @return list<array{path: string, name: string, mime: string, size: int, url: string, is_image: bool}>|JsonResponse
     */
    private function storeUploadedAttachments(Request $request, int $ownerId): array|JsonResponse
    {
        /** @var list<UploadedFile> $files */
        $files = [];
        $single = $request->file('file');
        if ($single instanceof UploadedFile) {
            $files[] = $single;
        }
        $multi = $request->file('files');
        if (is_array($multi)) {
            foreach ($multi as $item) {
                if ($item instanceof UploadedFile) {
                    $files[] = $item;
                }
            }
        }

        if ($files === []) {
            return [];
        }

        $stored = [];
        try {
            foreach (array_slice($files, 0, 5) as $file) {
                $stored[] = $this->attachments->store($file, $ownerId);
            }
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first()
                    ?? 'Tệp đính kèm không hợp lệ.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeMetadata(Request $request, ?string $pageUrl, ?string $connectionHash): array
    {
        $url = is_string($pageUrl) ? trim($pageUrl) : '';
        if ($url === '') {
            $url = (string) $request->headers->get('Referer', '');
        }

        // Strip query fragments that might carry tokens.
        $safeUrl = $this->sanitizeUrl($url);

        return [
            'page_url' => $safeUrl,
            'connection_hash' => $connectionHash,
            'app_version' => (string) config('app.version', config('app.env')),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at_client' => now()->toIso8601String(),
            'owner_id' => SeoAccessControl::accountOwnerId(),
        ];
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return mb_substr($url, 0, 500);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        if ($host === '') {
            // Relative path — keep path only.
            return mb_substr($path !== '' ? $path : $url, 0, 500);
        }

        return mb_substr($scheme.'://'.$host.$path, 0, 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(SupportTicket $ticket): array
    {
        $metadata = $ticket->metadata ?? [];
        $attachments = [];
        if (is_array($metadata['attachments'] ?? null)) {
            foreach ($metadata['attachments'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $attachments[] = [
                    'name' => (string) ($row['name'] ?? 'attachment'),
                    'url' => (string) ($row['url'] ?? ''),
                    'mime' => (string) ($row['mime'] ?? ''),
                    'size' => (int) ($row['size'] ?? 0),
                    'is_image' => (bool) ($row['is_image'] ?? false),
                ];
            }
        }

        return [
            'id' => (int) $ticket->id,
            'title' => (string) $ticket->title,
            'body' => (string) $ticket->body,
            'status' => (string) $ticket->status,
            'remote_ticket_id' => $ticket->remote_ticket_id,
            'last_error' => $ticket->last_error,
            'sent_at' => $ticket->sent_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'attachments' => $attachments,
            'metadata' => $metadata,
        ];
    }
}
