<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers;

use Omnichannel\Addons\Content\Services\TeamChatAttachmentService;
use Omnichannel\Addons\Content\Services\TeamChatNotificationService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use App\Models\TeamMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $ownerId = SeoAccessControl::accountOwnerId();
        if ($ownerId === null || $ownerId <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($request->boolean('unread_summary')) {
            $sinceId = max(0, (int) $request->query('since_id', 0));
            $userId = (int) auth()->id();

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

        // php artisan serve (cli-server) is single-threaded: a long-lived SSE loop
        // blocks Livewire / page navigations. Prefer short JSON poll there.
        if ($request->boolean('poll') || PHP_SAPI === 'cli-server') {
            return $this->pollJson($ownerId, $afterId);
        }

        return new StreamedResponse(function () use ($ownerId, $afterId): void {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            set_time_limit(0);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $cursorId = $afterId;
            $sendHistoryEnd = $cursorId === 0;

            if ($sendHistoryEnd) {
                $historyRows = TeamMessage::query()
                    ->where('owner_id', $ownerId)
                    ->with(['user:id,name,email'])
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get()
                    ->reverse()
                    ->values();

                foreach ($historyRows as $message) {
                    $this->emitSseMessage($message);
                    $cursorId = max($cursorId, (int) $message->id);
                }

                $this->emitSseEvent('history_end', [
                    'owner_id' => $ownerId,
                    'current_user_id' => (int) auth()->id(),
                    'config' => $this->attachmentService->clientConfig(),
                    'can_use_ai' => ! SeoAccessControl::isContentManager(),
                ]);
            }

            $lastHeartbeatAt = microtime(true);

            while (true) {
                if (connection_aborted() !== 0) {
                    break;
                }

                $newRows = TeamMessage::query()
                    ->where('owner_id', $ownerId)
                    ->where('id', '>', $cursorId)
                    ->with(['user:id,name,email'])
                    ->orderBy('id')
                    ->limit(100)
                    ->get();

                foreach ($newRows as $message) {
                    $this->emitSseMessage($message);
                    $cursorId = (int) $message->id;
                }

                $now = microtime(true);
                if ($now - $lastHeartbeatAt >= 2.0) {
                    echo ':'."\n\n";
                    $this->flushSseOutput();
                    $lastHeartbeatAt = $now;
                }

                usleep(500_000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
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

        return response()->json([
            'success' => true,
            'message' => $this->serializeMessage($message),
        ], 201);
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
    private function notifyWorkspaceMembers(TeamMessage $message): void
    {
        if (! class_exists(TeamChatNotificationService::class)) {
            return;
        }

        app(TeamChatNotificationService::class)->notifyWorkspaceMembers($message);
    }

    private function emitSseMessage(TeamMessage $message): void
    {
        echo 'data: '.json_encode($this->serializeMessage($message), JSON_THROW_ON_ERROR)."\n\n";
        $this->flushSseOutput();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitSseEvent(string $event, array $payload): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
        $this->flushSseOutput();
    }

    private function flushSseOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

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
}
