<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Persistent SaaS capacity status for Rescue Mode rail (non-dismissible).
 */
final class AiCapacityStatusService
{
    public const STATE_HEALTHY = 'healthy';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_RESCUE = 'rescue';

    public const STATE_CRITICAL = 'critical';

    /**
     * @return array{
     *   state: string,
     *   paid_available: bool,
     *   free_text_available: bool,
     *   image_paused: bool,
     *   video_paused: bool,
     *   message: string|null,
     *   action_url: string|null
     * }
     */
    public function status(?int $userId = null): array
    {
        $userId = $userId ?? (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            return $this->payload(self::STATE_HEALTHY, true, false, false, false, null);
        }

        $targets = app(AiRoutingTargetService::class);
        $pool = app(OpenRouterFreePoolService::class);
        $paidAvailable = false;
        $freeAvailable = false;

        foreach ([AiExecutionProfile::TextFast, AiExecutionProfile::TextLongform, AiExecutionProfile::TextReasoning] as $profile) {
            try {
                foreach ($targets->liveCompatibleCandidates($userId, $profile) as $candidate) {
                    // Synthetic openrouter/free is not Free Rescue capacity — only
                    // OpenRouterFreePoolService::runtimeMembers (gate-aware) count.
                    if (! $candidate->isFree) {
                        $paidAvailable = true;
                    }
                }
            } catch (\Throwable) {
            }
        }

        foreach (AiModelArea::textPrimaryCases() as $area) {
            try {
                if ($pool->runtimeMembers($userId, $area) !== []) {
                    $freeAvailable = true;
                    break;
                }
            } catch (\Throwable) {
            }
        }

        $imagePaused = ! $paidAvailable;
        $videoPaused = ! $paidAvailable;

        if ($paidAvailable) {
            // Some paid may still be degraded; keep rail quiet when any paid works.
            return $this->payload(self::STATE_HEALTHY, true, $freeAvailable, false, false, null);
        }

        if ($freeAvailable) {
            return $this->payload(
                self::STATE_RESCUE,
                false,
                true,
                $imagePaused,
                $videoPaused,
                'AI đang chạy ở chế độ tiết kiệm. Các kết nối AI trả phí hiện không còn hạn mức. Text đang sử dụng model miễn phí dự phòng; tạo ảnh và video tạm dừng.',
            );
        }

        return $this->payload(
            self::STATE_CRITICAL,
            false,
            false,
            true,
            true,
            'Hiện không có kết nối AI khả dụng. Hãy kiểm tra API Connections.',
        );
    }

    /**
     * @return array{
     *   state: string,
     *   paid_available: bool,
     *   free_text_available: bool,
     *   image_paused: bool,
     *   video_paused: bool,
     *   message: string|null,
     *   action_url: string|null
     * }
     */
    private function payload(
        string $state,
        bool $paid,
        bool $free,
        bool $imagePaused,
        bool $videoPaused,
        ?string $message,
    ): array {
        $url = null;
        try {
            $url = \Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource::getUrl(
                'index',
                panel: 'admin',
            );
        } catch (\Throwable) {
            $url = '/admin/settings/api';
        }

        return [
            'state' => $state,
            'paid_available' => $paid,
            'free_text_available' => $free,
            'image_paused' => $imagePaused,
            'video_paused' => $videoPaused,
            'message' => $message,
            'action_url' => $url,
        ];
    }
}
