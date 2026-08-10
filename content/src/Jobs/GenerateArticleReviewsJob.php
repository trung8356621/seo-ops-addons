<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Jobs;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleQuickPostReviewService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class GenerateArticleReviewsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $articleId,
        public int $userId,
    ) {}

    public function handle(
        ArticleQuickPostReviewService $reviewService,
        SeoDatabaseConnectionService $databaseConnection,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $article = SeoArticle::query()->find($this->articleId);
        if ($article instanceof SeoArticle && (int) ($article->site_id ?? 0) > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection((int) $article->site_id);
            $article = SeoArticle::query()->find($this->articleId);
        }

        if (! $article instanceof SeoArticle) {
            $this->notifyUser(false, 'Không tìm thấy bài viết #'.$this->articleId.'.');

            return;
        }

        $user = User::query()->find($this->userId);
        if ($user !== null) {
            auth()->setUser($user);
        }

        try {
            $result = $reviewService->runForArticle($article);
        } catch (Throwable $exception) {
            $this->notifyUser(false, $exception->getMessage());
            Cache::forget($this->readyCacheKey());

            return;
        }

        $success = (bool) ($result['success'] ?? false);
        $this->notifyUser($success, (string) ($result['message'] ?? ''));

        if ($success) {
            Cache::put($this->readyCacheKey(), true, now()->addMinutes(10));
        } else {
            Cache::forget($this->readyCacheKey());
        }
    }

    private function notifyUser(bool $success, string $message): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title(
                $success
                    ? __('seo-content-ai::filament.article_list.quick_create_reviews_success')
                    : __('seo-content-ai::filament.article_list.quick_create_reviews_failed'),
            )
            ->body($message !== '' ? $message : ' ');

        $success ? $notification->success() : $notification->danger();

        $notification->sendToDatabase($user);
    }

    private function readyCacheKey(): string
    {
        return 'seo_article_reviews_ready:'.$this->articleId.':'.$this->userId;
    }
}
