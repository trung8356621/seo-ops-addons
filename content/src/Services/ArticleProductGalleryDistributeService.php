<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\SeoMedia;

/**
 * Phân phối ảnh gallery sản phẩm vào các section còn trống trong body HTML.
 *
 * Mỗi section được xác định bởi thẻ <h2>. Section nào chưa có <img> sẽ
 * được chèn ảnh tiếp theo từ danh sách gallery vào ngay sau thẻ <h2>.
 * Intro (trước <h2> đầu tiên) và section có FAQ shortcode sẽ bị bỏ qua.
 */
final class ArticleProductGalleryDistributeService
{
    /**
     * @param  list<array{id: int, url: string}>  $galleryItems
     */
    public function distribute(SeoArticle $article, array $galleryItems): int
    {
        $body = trim((string) ($article->body ?? ''));
        if ($body === '' || $galleryItems === []) {
            return 0;
        }

        $result = $this->insertImagesToEmptySections($body, $galleryItems, $article);
        if ($result['inserted'] === 0) {
            return 0;
        }

        $article->update(['body' => $result['html']]);
        try {
            $writer = app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class);
            $writer->invalidateForLegacyBodyWrite($article, 'product_gallery_distribute');
            if ($article->isDirty('editor_document_status')) {
                $article->save();
            }
        } catch (\Throwable) {
            // best-effort
        }
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_post_content'],
            ['meta_value' => $result['html']],
        );

        return $result['inserted'];
    }

    /**
     * @param  list<array{id: int, url: string}>  $galleryItems
     * @return array{html: string, inserted: int}
     */
    public function insertImagesToEmptySections(string $html, array $galleryItems, ?SeoArticle $article = null): array
    {
        // Tách sections theo thẻ <h2> — dùng preg_split để giữ delimiter
        // Pattern: <h2 ...>...</h2> — opening tag có thể không có attr
        $parts = preg_split('/(<h2(?:\s[^>]*)?>.*?<\/h2>)/isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        if (count($parts) <= 1) {
            return ['html' => $html, 'inserted' => 0];
        }

        // parts layout: [intro, h2_0, content_0, h2_1, content_1, ...]
        // intro = $parts[0], then pairs: ($parts[1], $parts[2]), ($parts[3], $parts[4])…

        $siteId = (int) ($article?->site_id ?? 0);

        $pool = array_values(array_filter(
            $galleryItems,
            static fn (array $item): bool => trim((string) ($item['url'] ?? '')) !== '',
        ));
        $cursor = 0;
        $inserted = 0;

        $result = [$parts[0]]; // intro

        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $h2Tag = $parts[$i];
            $sectionContent = $parts[$i + 1] ?? '';

            // Bỏ qua section có FAQ shortcode
            if ($this->sectionHasFaq($sectionContent)) {
                $result[] = $h2Tag;
                $result[] = $sectionContent;

                continue;
            }

            // Kiểm tra section đã có ảnh chưa
            if ($this->sectionHasImage($sectionContent)) {
                $result[] = $h2Tag;
                $result[] = $sectionContent;

                continue;
            }

            // Không còn ảnh trong pool
            if ($cursor >= count($pool)) {
                $result[] = $h2Tag;
                $result[] = $sectionContent;

                continue;
            }

            $item = $pool[$cursor++];
            $imgHtml = $this->buildImgHtml($item, $siteId);

            $result[] = $h2Tag;
            $result[] = "\n".$imgHtml.$sectionContent;
            $inserted++;
        }

        // Nếu số phần lẻ (không đủ cặp) — thêm phần cuối thừa
        if (count($parts) % 2 === 0) {
            $result[] = $parts[count($parts) - 1];
        }

        return [
            'html' => implode('', $result),
            'inserted' => $inserted,
        ];
    }

    /**
     * @param  array{id: int, url: string}  $item
     */
    private function buildImgHtml(array $item, int $siteId): string
    {
        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            return '';
        }

        $mediaId = (int) ($item['id'] ?? 0);
        $slug = $this->resolveSlug($url, $mediaId, $siteId);
        $alt = $this->resolveAltText($mediaId);
        if ($alt === '') {
            $alt = $slug !== '' ? str_replace('-', ' ', $slug) : '';
        }

        $attrs = [
            'src="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'"',
        ];

        if ($alt !== '') {
            $attrs[] = 'alt="'.htmlspecialchars($alt, ENT_QUOTES, 'UTF-8').'"';
            $attrs[] = 'title="'.htmlspecialchars($alt, ENT_QUOTES, 'UTF-8').'"';
        }

        $wpAttachmentId = (int) ($item['wp_attachment_id'] ?? 0);
        if ($wpAttachmentId > 0) {
            $attrs[] = 'data-id="'.$wpAttachmentId.'"';
            $attrs[] = 'class="aligncenter wp-image-'.$wpAttachmentId.'"';
        } else {
            $attrs[] = 'class="aligncenter"';
        }

        if ($mediaId > 0) {
            $attrs[] = 'data-seo-media-id="'.$mediaId.'"';
        }

        return '<figure class="wp-caption aligncenter"><img '.implode(' ', $attrs)." /></figure>\n";
    }

    private function resolveAltText(int $mediaId): string
    {
        if ($mediaId <= 0) {
            return '';
        }

        $media = SeoMedia::query()->whereKey($mediaId)->first();

        return $media instanceof SeoMedia ? trim((string) ($media->alt_text ?? '')) : '';
    }

    private function resolveSlug(string $url, int $mediaId, int $siteId): string
    {
        if ($mediaId > 0) {
            $media = SeoMedia::query()->whereKey($mediaId)->first();
            if ($media instanceof SeoMedia) {
                $slug = trim((string) ($media->filename ?? ''));
                // Strip extension and numbered suffix like -2-3
                $slug = (string) preg_replace('/\.[a-z]{2,5}$/iu', '', $slug);
                $slug = (string) preg_replace('/-\d+-\d+$/', '', $slug);

                return $slug;
            }
        }

        // Fallback from URL
        $path = basename((string) parse_url($url, PHP_URL_PATH));

        return (string) preg_replace('/\.[a-z]{2,5}$/iu', '', $path);
    }

    private function sectionHasImage(string $html): bool
    {
        return (bool) preg_match('/<img[\s>]/iu', $html);
    }

    private function sectionHasFaq(string $html): bool
    {
        return str_contains($html, '[omi_faq]')
            || str_contains($html, 'omi-faq')
            || (bool) preg_match('/\[faq[^\]]*\]/iu', $html);
    }
}
