<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers;

use Omnichannel\Addons\Content\Services\TeamChatAttachmentService;
use Omnichannel\Addons\Content\Services\TeamChatNotificationService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\TeamChatReadCursor;
use App\Models\TeamMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Team group chat JSON API. Clients own poll/send UI state.
 * Long-lived SSE is retired — always return short JSON (pollJson).
 */
final class TeamMessageController extends Controller
{
    public function __construct(
        private readonly TeamChatAttachmentService $attachmentService,
    ) {}

    public function config(): JsonResponse
    {
        $ownerId = SeoAccessControl::accountOwnerId();
        if ($ownerId === null || $ownerId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'config' => $this->attachmentService->clientConfig(),
            'can_use_ai' => ! SeoAccessControl::isContentManager(),
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        $ownerId = SeoAccessControl::accountOwnerId();
        if ($ownerId === null || $ownerId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $userId = (int) auth()->id();
        $sinceId = $this->resolveLastReadMessageId($ownerId, $userId);

        $unread = TeamMessage::query()
            ->where('owner_id', $ownerId)
            ->where('id', '>', $sinceId)
            ->when($userId > 0, fn ($query) => $query->where('user_id', '!=', $userId))
            ->count();

        return response()->json([
            'unread' => $unread,
            'last_read_message_id' => $sinceId,
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $ownerId = SeoAccessControl::accountOwnerId();
        if ($ownerId === null || $ownerId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $userId = (int) auth()->id();
        if ($userId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'last_read_message_id' => ['required', 'integer', 'min:0'],
        ]);

        $lastRead = (int) $validated['last_read_message_id'];
        $this->upsertReadCursor($ownerId, $userId, $lastRead);

        return response()->json([
            'ok' => true,
            'last_read_message_id' => $lastRead,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $ownerId = SeoAccessControl::accountOwnerId();
        if ($ownerId === null || $ownerId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($request->boolean('unread_summary')) {
            $sinceId = max(0, (int) $request->query('since_id', 0));
            $userId = (int) auth()->id();

            if ($sinceId === 0 && Schema::hasTable('team_chat_read_cursors')) {
                $sinceId = $this->resolveLastReadMessageId($ownerId, $userId);
            }

            $unreadCount = TeamMessage::query()
                ->where('owner_id', $ownerId)
                ->where('id', '>', $sinceId)
                ->when($userId > 0, fn ($query) => $query->where('user_id', '!=', $userId))
                ->count();

            $latestId = (int) (TeamMessage::query()
                ->where('owner_id', $ownerId)
                ->max('id') ?? 0);

            return response()->json([
                'unread_count' => $unreadCount,
                'latest_message_id' => $latestId,
                'owner_id' => $ownerId,
            ]);
        }

        $afterId = max(0, (int) $request->query('after_id', $request->query('last_id', 0)));

        // Always JSON poll — never long-lived SSE (blocks php artisan serve / workers).
        return $this->pollJson($ownerId, $afterId);
    }

    private function pollJson(int $ownerId, int $afterId): JsonResponse
    {
        $sendHistory = $afterId === 0;

        $query = TeamMessage::query()
            ->where('owner_id', $ownerId)
            ->with(['user:id,name,email']);

        if ($sendHistory) {
            $rows = $query
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->reverse()
                ->values();
        } else {
            $rows = $query
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit(100)
                ->get();
        }

        return response()->json([
            'messages' => $rows->map(fn (TeamMessage $message): array => $this->serializeMessage($message))->values()->all(),
            'owner_id' => $ownerId,
            'current_user_id' => (int) auth()->id(),
            'config' => $this->attachmentService->clientConfig(),
            'can_use_ai' => ! SeoAccessControl::isContentManager(),
            'history_end' => $sendHistory,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $ownerId = SeoAccessControl::accountOwnerId();
        if ($ownerId === null || $ownerId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'file' => ['nullable', 'file'],
        ]);

        $text = trim(preg_replace('/\s+/u', ' ', (string) ($validated['message'] ?? '')) ?? '');
        $uploadedFile = $request->file('file');

        if ($text === '' && $uploadedFile === null) {
            return response()->json([
                'message' => 'Nội dung tin nhắn hoặc tệp đính kèm là bắt buộc.',
            ], 422);
        }

        /** @var User $sender */
        $sender = $request->user();

        $attachment = null;
        if ($uploadedFile !== null) {
            try {
                $attachment = $this->attachmentService->store($uploadedFile, $ownerId);
            } catch (ValidationException $exception) {
                return response()->json([
                    'message' => collect($exception->errors())->flatten()->first()
                        ?? 'Tệp đính kèm không hợp lệ.',
                    'errors' => $exception->errors(),
                ], 422);
            }
        }

        $message = TeamMessage::query()->create([
            'owner_id' => $ownerId,
            'user_id' => (int) $sender->id,
            'message' => $text,
            'attachment_path' => $attachment['path'] ?? null,
            'attachment_name' => $attachment['name'] ?? null,
            'attachment_mime' => $attachment['mime'] ?? null,
            'attachment_size' => $attachment['size'] ?? null,
        ]);

        $message->load(['user:id,name,email']);

        $this->notifyWorkspaceMembers($message);
        $this->upsertReadCursor($ownerId, (int) $sender->id, (int) $message->id);

        return response()->json([
            'success' => true,
            'message' => $this->serializeMessage($message),
        ], 201);
    }

    private function notifyWorkspaceMembers(TeamMessage $message): void
    {
        if (! class_exists(TeamChatNotificationService::class)) {
            return;
        }

        app(TeamChatNotificationService::class)->notifyWorkspaceMembers($message);
    }

    /**
     * @return array{
     *     id: int,
     *     owner_id: int,
     *     user_id: int,
     *     user_name: string,
     *     user_email: string,
     *     message: string,
     *     attachment_url: string|null,
     *     attachment_name: string|null,
     *     attachment_mime: string|null,
     *     attachment_size: int|null,
     *     attachment_is_image: bool,
     *     created_at: string|null,
     *     is_mine: bool
     * }
     */
    private function serializeMessage(TeamMessage $message): array
    {
        $user = $message->user;
        $mime = (string) ($message->attachment_mime ?? '');
        $path = (string) ($message->attachment_path ?? '');

        return [
            'id' => (int) $message->id,
            'owner_id' => (int) $message->owner_id,
            'user_id' => (int) $message->user_id,
            'user_name' => trim((string) ($user?->name ?? 'Thành viên')),
            'user_email' => trim((string) ($user?->email ?? '')),
            'message' => (string) $message->message,
            'attachment_url' => $path !== '' ? Storage::disk('public')->url($path) : null,
            'attachment_name' => filled($message->attachment_name) ? (string) $message->attachment_name : null,
            'attachment_mime' => $mime !== '' ? $mime : null,
            'attachment_size' => $message->attachment_size !== null ? (int) $message->attachment_size : null,
            'attachment_is_image' => $mime !== '' && str_starts_with($mime, 'image/'),
            'created_at' => $message->created_at?->toIso8601String(),
            'is_mine' => (int) auth()->id() === (int) $message->user_id,
        ];
    }

    private function resolveLastReadMessageId(int $ownerId, int $userId): int
    {
        if ($userId <= 0 || ! Schema::hasTable('team_chat_read_cursors')) {
            return 0;
        }

        $cursor = TeamChatReadCursor::query()
            ->where('owner_id', $ownerId)
            ->where('user_id', $userId)
            ->value('last_read_message_id');

        return max(0, (int) $cursor);
    }

    private function upsertReadCursor(int $ownerId, int $userId, int $lastReadMessageId): void
    {
        if ($userId <= 0 || ! Schema::hasTable('team_chat_read_cursors')) {
            return;
        }

        $existing = TeamChatReadCursor::query()
            ->where('owner_id', $ownerId)
            ->where('user_id', $userId)
            ->first();

        if ($existing === null) {
            TeamChatReadCursor::query()->create([
                'owner_id' => $ownerId,
                'user_id' => $userId,
                'last_read_message_id' => max(0, $lastReadMessageId),
            ]);

            return;
        }

        if ($lastReadMessageId > (int) $existing->last_read_message_id) {
            $existing->last_read_message_id = $lastReadMessageId;
            $existing->save();
        }
    }
}
