<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;

final class CreateArticleWorkflowNotification
{
    /**
     * @param  array{created: int, failed: int, messages: list<string>, article_ids?: list<int>}  $result
     */
    public static function send(array $result, string $title = 'Đã xử lý từ khóa'): void
    {
        $body = sprintf(
            'Thành công: %d · Lỗi: %d',
            $result['created'],
            $result['failed'],
        );

        if ($result['messages'] !== []) {
            $body .= "\n" . implode("\n", array_slice($result['messages'], 0, 8));
            if (count($result['messages']) > 8) {
                $body .= "\n…";
            }
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        if ($result['failed'] > 0 && $result['created'] === 0) {
            $notification->danger();
        } elseif ($result['failed'] > 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $articleId = (int) ($result['article_ids'][0] ?? 0);
        if ($articleId > 0) {
            $notification->actions([
                Action::make('edit')
                    ->label('Mở bài viết')
                    ->url(ArticleResource::getUrl('edit', ['record' => $articleId]))
                    ->button(),
            ]);
        }

        $notification->send();
    }
}
