<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\TeamMessage;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class TeamChatNotificationService
{
    public function notifyWorkspaceMembers(TeamMessage $message): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $message->loadMissing(['user:id,name,email']);

        $ownerId = (int) $message->owner_id;
        $senderId = (int) $message->user_id;
        if ($ownerId <= 0 || $senderId <= 0) {
            return;
        }

        $senderName = trim((string) ($message->user?->name ?? ''));
        if ($senderName === '') {
            $senderName = (string) __('seo-content-ai::filament.workspace_chat.notify_unknown_sender');
        }

        $preview = $this->previewText($message);

        foreach ($this->workspaceMembers($ownerId, $senderId) as $recipient) {
            Notification::make()
                ->title(__('seo-content-ai::filament.workspace_chat.notify_new_message_title', [
                    'name' => $senderName,
                ]))
                ->body($preview)
                ->icon('heroicon-o-chat-bubble-left-right')
                ->sendToDatabase($recipient);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function workspaceMembers(int $ownerId, int $excludeUserId): Collection
    {
        return User::query()
            ->where('status', User::STATUS_NORMAL)
            ->where(function ($query) use ($ownerId): void {
                $query->whereKey($ownerId)
                    ->orWhere(function ($staffQuery) use ($ownerId): void {
                        $staffQuery
                            ->where('parent_id', $ownerId)
                            ->where('role', User::ROLE_STAFF);
                    });
            })
            ->whereKeyNot($excludeUserId)
            ->get()
            ->filter(static fn (User $user): bool => SeoAccessControl::canAccessSeoPanel($user))
            ->values();
    }

    private function previewText(TeamMessage $message): string
    {
        $text = trim((string) $message->message);
        if ($text !== '') {
            return mb_strlen($text) > 120
                ? mb_substr($text, 0, 117).'...'
                : $text;
        }

        if (filled($message->attachment_name)) {
            return '📎 '.trim((string) $message->attachment_name);
        }

        return (string) __('seo-content-ai::filament.workspace_chat.notify_new_message_attachment');
    }
}
