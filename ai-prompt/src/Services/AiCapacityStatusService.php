<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Persistent SaaS capacity status for Rescue Mode rail (non-dismissible).
 *
 * Rail visibility is gated separately ({@see viewerMayInspectCapacity()}): only users
 * who can manage API Connections see the banner. Capacity itself is evaluated against
 * workspace inventory (owner/admin), never against a viewer-scoped empty connection set.
 */
final class AiCapacityStatusService
{
    public const STATE_HEALTHY = 'healthy';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_RESCUE = 'rescue';

    public const STATE_CRITICAL = 'critical';

    /**
     * Whether the authenticated user may see / troubleshoot the capacity rail.
     * Delegates to API Connections resource access — not a role-name hardcode.
     */
    public function viewerMayInspectCapacity(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        if (class_exists(\Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource::class)) {
            return \Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource::canViewAny();
        }

        if (class_exists(\Omnichannel\Addons\Seo\Support\SeoAccessControl::class)) {
            return \Omnichannel\Addons\Seo\Support\SeoAccessControl::canAccessManagerFeatures();
        }

        return false;
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
    public function status(?int $userId = null): array
    {
        $evaluationUserId = $this->resolveWorkspaceCapacityUserId($userId);
        if ($evaluationUserId <= 0) {
            return $this->payload(self::STATE_HEALTHY, true, false, false, false, null);
        }

        $targets = app(AiRoutingTargetService::class);
        $pool = app(OpenRouterFreePoolService::class);
        $paidAvailable = false;
        $freeAvailable = false;

        foreach ([AiExecutionProfile::TextFast, AiExecutionProfile::TextLongform, AiExecutionProfile::TextReasoning] as $profile) {
            try {
                foreach ($targets->liveCompatibleCandidates($evaluationUserId, $profile) as $candidate) {
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
                if ($pool->runtimeMembers($evaluationUserId, $area) !== []) {
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
                'AI đang chạy ở chế độ tiết kiệm. Các kết nối AI trả phí hiện không còn hạn mức. Hệ thống đang sử dụng model miễn phí dự phòng; tạo ảnh và video tạm dừng.',
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
     * Prefer a user who sees full workspace AI inventory (owner/admin).
     * Avoid evaluating capacity under a restricted viewer (e.g. content_manager)
     * whose empty personal connection set would falsely yield CRITICAL.
     */
    public function resolveWorkspaceCapacityUserId(?int $preferredUserId = null): int
    {
        $preferred = $preferredUserId ?? (int) (auth()->id() ?? 0);
        $inventory = app(AiConnectionInventoryService::class);

        if ($preferred > 0 && $inventory->viewerSeesWorkspaceInventory($preferred)) {
            return $preferred;
        }

        $workspaceOwnerId = $this->firstWorkspaceInventoryOwnerId();
        if ($workspaceOwnerId > 0) {
            return $workspaceOwnerId;
        }

        // Unit fixtures / environments without owner rows: keep preferred id.
        return $preferred;
    }

    private function firstWorkspaceInventoryOwnerId(): int
    {
        try {
            return (int) (DB::table('users')
                ->whereIn('role', [
                    defined(User::class.'::ROLE_OWNER') ? User::ROLE_OWNER : 'owner',
                    defined(User::class.'::ROLE_ADMIN') ? User::ROLE_ADMIN : 'admin',
                ])
                ->orderBy('id')
                ->value('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
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
