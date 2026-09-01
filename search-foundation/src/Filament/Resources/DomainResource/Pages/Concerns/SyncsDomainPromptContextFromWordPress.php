<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Filament\Resources\DomainResource\Pages\Concerns;

use Omnichannel\Addons\AiPrompt\Services\DomainPromptContextWordPressFieldSyncService;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Throwable;

trait SyncsDomainPromptContextFromWordPress
{
    public bool $syncingCompanyShortIdentityFromWp = false;

    public bool $syncingShortDescriptionFromWp = false;

    public function syncCompanyShortIdentityFromWordPress(Set $set): void
    {
        if ($this->syncingCompanyShortIdentityFromWp) {
            return;
        }

        $this->syncingCompanyShortIdentityFromWp = true;

        try {
            /** @var Site $site */
            $site = $this->record;
            $result = app(DomainPromptContextWordPressFieldSyncService::class)
                ->syncCompanyShortIdentity($site);

            if (($result['success'] ?? false) === true) {
                $set('company_short_identity', (string) ($result['value'] ?? ''));

                $notification = Notification::make()
                    ->title((string) ($result['message'] ?? 'Đã đồng bộ Tiêu đề website từ WordPress.'));

                if (($result['was_clamped'] ?? false) === true) {
                    $notification->body('Giá trị đã được rút gọn xuống 80 ký tự.');
                }

                $notification->success()->send();

                return;
            }

            $this->notifyWordPressFieldSyncFailure($result);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => '/omi-seo-ai/v1/sync/v2/profile',
                'site_id' => (int) $this->record->getKey(),
                'field' => 'company_short_identity',
            ]);

            Notification::make()
                ->title('Không thể đọc thông tin website từ WordPress.')
                ->danger()
                ->send();
        } finally {
            $this->syncingCompanyShortIdentityFromWp = false;
        }
    }

    public function syncShortDescriptionFromWordPress(Set $set): void
    {
        if ($this->syncingShortDescriptionFromWp) {
            return;
        }

        $this->syncingShortDescriptionFromWp = true;

        try {
            /** @var Site $site */
            $site = $this->record;
            $result = app(DomainPromptContextWordPressFieldSyncService::class)
                ->syncShortDescription($site);

            if (($result['success'] ?? false) === true) {
                $set('short_description', (string) ($result['value'] ?? ''));

                Notification::make()
                    ->title((string) ($result['message'] ?? 'Đã đồng bộ Dòng mô tả từ WordPress.'))
                    ->success()
                    ->send();

                return;
            }

            $this->notifyWordPressFieldSyncFailure($result);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => '/omi-seo-ai/v1/sync/v2/profile',
                'site_id' => (int) $this->record->getKey(),
                'field' => 'short_description',
            ]);

            Notification::make()
                ->title('Không thể đọc thông tin website từ WordPress.')
                ->danger()
                ->send();
        } finally {
            $this->syncingShortDescriptionFromWp = false;
        }
    }

    /**
     * @param  array{success?: bool, level?: string, message?: string}  $result
     */
    private function notifyWordPressFieldSyncFailure(array $result): void
    {
        $message = trim((string) ($result['message'] ?? 'Không thể đọc thông tin website từ WordPress.'));
        $level = (string) ($result['level'] ?? 'danger');

        $notification = Notification::make()->title($message);

        match ($level) {
            'warning' => $notification->warning(),
            default => $notification->danger(),
        };

        $notification->send();
    }
}
