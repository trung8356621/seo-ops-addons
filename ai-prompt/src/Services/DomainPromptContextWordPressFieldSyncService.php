<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\SiteSync\Services\Profile\WordPressSiteProfileReader;
use App\Models\Site;
use App\Support\RuntimeLogger;

/**
 * Explicit field-level WordPress sync for Domain Prompt Context — never full Site Sync.
 */
final class DomainPromptContextWordPressFieldSyncService
{
    private const PROFILE_ENDPOINT = '/omi-seo-ai/v1/sync/v2/profile';

    public function __construct(
        private readonly WordPressSiteProfileReader $profileReader,
        private readonly SiteDomainPromptContextService $contextService,
        private readonly WordPressFieldSyncAccessGate $accessGate,
    ) {}

    /**
     * @return array{success: bool, level: string, message: string, value?: string, was_clamped?: bool}
     */
    public function syncCompanyShortIdentity(Site $site): array
    {
        if (! $this->accessGate->canSync($site)) {
            return $this->denied();
        }

        $profile = $this->profileReader->read($site);
        if (! ($profile['success'] ?? false)) {
            return $this->bridgeFailure($site, (string) ($profile['message'] ?? ''));
        }

        $rawName = trim((string) ($profile['site_name'] ?? ''));
        if ($rawName === '') {
            return [
                'success' => false,
                'level' => 'warning',
                'message' => 'WordPress chưa có Tiêu đề trang web.',
            ];
        }

        $clamped = $this->contextService->clampCompanyShortIdentity($rawName);
        $wasClamped = mb_strlen($rawName) > mb_strlen($clamped);

        try {
            $this->contextService->patchForSite($site, [
                'company_short_identity' => $clamped,
            ]);
        } catch (\Throwable $e) {
            return $this->bridgeFailure($site, $e->getMessage());
        }

        return [
            'success' => true,
            'level' => 'success',
            'message' => 'Đã đồng bộ Tiêu đề website từ WordPress.',
            'value' => $clamped,
            'was_clamped' => $wasClamped,
        ];
    }

    /**
     * @return array{success: bool, level: string, message: string, value?: string}
     */
    public function syncShortDescription(Site $site): array
    {
        if (! $this->accessGate->canSync($site)) {
            return $this->denied();
        }

        $profile = $this->profileReader->read($site);
        if (! ($profile['success'] ?? false)) {
            return $this->bridgeFailure($site, (string) ($profile['message'] ?? ''));
        }

        $raw = trim((string) ($profile['short_description'] ?? ''));
        if ($raw === '') {
            return [
                'success' => false,
                'level' => 'warning',
                'message' => 'WordPress chưa có Dòng mô tả.',
            ];
        }

        if ($this->contextService->countWords($raw) > SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS) {
            return [
                'success' => false,
                'level' => 'warning',
                'message' => 'Dòng mô tả WordPress vượt quá '
                    .SiteDomainPromptContextService::MAX_SHORT_DESCRIPTION_WORDS.' từ.',
            ];
        }

        try {
            $this->contextService->patchForSite($site, [
                'short_description' => $raw,
            ]);
        } catch (\InvalidArgumentException $e) {
            return [
                'success' => false,
                'level' => 'warning',
                'message' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return $this->bridgeFailure($site, $e->getMessage());
        }

        return [
            'success' => true,
            'level' => 'success',
            'message' => 'Đã đồng bộ Dòng mô tả từ WordPress.',
            'value' => $raw,
        ];
    }

    /**
     * @return array{success: false, level: string, message: string}
     */
    private function denied(): array
    {
        return [
            'success' => false,
            'level' => 'danger',
            'message' => 'Không có quyền đồng bộ domain này.',
        ];
    }

    /**
     * @return array{success: false, level: string, message: string}
     */
    private function bridgeFailure(Site $site, string $detail): array
    {
        RuntimeLogger::warning('domain_prompt_context.wp_field_sync_failed', [
            'site_id' => (int) $site->id,
            'endpoint' => self::PROFILE_ENDPOINT,
            'error' => $detail,
        ]);

        return [
            'success' => false,
            'level' => 'danger',
            'message' => 'Không thể đọc thông tin website từ WordPress.',
        ];
    }
}
