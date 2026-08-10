<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use App\Models\Site;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;

/**
 * Site-level WordPress media library browse capability (not article wp_post_id).
 */
final class WordPressMediaCapabilityResolver
{
    public function __construct(
        private readonly WordPressArticleContentService $wpContent,
    ) {}

    /**
     * @return array{available: bool, reason: string|null}
     */
    public function forSite(Site|int|null $site): array
    {
        if ($site === null) {
            return [
                'available' => false,
                'reason' => 'Bài viết chưa gắn domain / WordPress connection.',
            ];
        }

        $site = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($site === null) {
            return [
                'available' => false,
                'reason' => 'Không tìm thấy domain của bài viết.',
            ];
        }

        $site->loadMissing('metas');
        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [
                'available' => false,
                'reason' => 'Thiếu Read token trên domain. Cấu hình tại Danh sách tên miền.',
            ];
        }

        $base = $this->wpContent->getPermalinkBase($site);
        if ($base === '') {
            return [
                'available' => false,
                'reason' => 'Không xác định được URL WordPress của domain.',
            ];
        }

        return [
            'available' => true,
            'reason' => null,
        ];
    }
}
