<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Content\Support\Utf8Sanitizer;
use App\Models\Site;

final class PromptLoaiSanPhamOptionsService
{
    /**
     * @return array<int, string>
     */
    public function siteOptionsForUser(): array
    {
        $query = Site::query()->orderBy('domain');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query
            ->get()
            ->mapWithKeys(static function (Site $site): array {
                $domain = trim((string) $site->domain);

                return [(int) $site->id => $domain !== '' ? $domain : 'Site #'.$site->id];
            })
            ->all();
    }

    /**
     * Danh mục product_cat đã đồng bộ (type = product_category) theo site.
     *
     * @return array<int, string> article_id => label
     */
    public function productCategoryOptionsForSite(?int $siteId): array
    {
        if ($siteId === null || $siteId <= 0) {
            return [];
        }

        return SeoArticle::query()
            ->where('site_id', $siteId)
            ->where('type', 'product_category')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(static function (SeoArticle $article): array {
                $title = trim((string) ($article->title ?? ''));
                $wpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
                $label = $title !== '' ? $title : 'Danh mục #'.$article->id;
                if ($wpId > 0) {
                    $label .= ' (WP #'.$wpId.')';
                }

                return [(int) $article->id => $label];
            })
            ->all();
    }

    public function buildCompositeValue(int $siteId, int $categoryArticleId, string $custom): string
    {
        $parts = [];

        if ($categoryArticleId > 0) {
            $article = SeoArticle::query()->find($categoryArticleId);
            if ($article instanceof SeoArticle && (int) $article->site_id === $siteId) {
                $title = trim(Utf8Sanitizer::string((string) ($article->title ?? '')));
                if ($title !== '') {
                    $parts[] = $title;
                }
            }
        }

        if ($custom !== '') {
            $parts[] = Utf8Sanitizer::string($custom);
        }

        return Utf8Sanitizer::string(implode(' — ', $parts));
    }

    /**
     * @return array{valid: bool, message: ?string}
     */
    public function validateTestInputs(int $siteId, int $categoryArticleId, string $custom = ''): array
    {
        $custom = trim($custom);

        if ($siteId <= 0) {
            return [
                'valid' => false,
                'message' => 'Chọn tên miền trước khi chạy thử (biến loai_san_pham).',
            ];
        }

        if ($categoryArticleId <= 0) {
            if ($custom !== '') {
                return ['valid' => true, 'message' => null];
            }

            return [
                'valid' => false,
                'message' => 'Chọn danh mục product_cat hoặc điền Custom (biến loai_san_pham).',
            ];
        }

        $exists = SeoArticle::query()
            ->whereKey($categoryArticleId)
            ->where('site_id', $siteId)
            ->where('type', 'product_category')
            ->exists();

        if (! $exists) {
            return [
                'valid' => false,
                'message' => 'Danh mục không hợp lệ hoặc chưa đồng bộ product_cat cho tên miền này.',
            ];
        }

        return ['valid' => true, 'message' => null];
    }
}
