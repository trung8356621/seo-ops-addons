<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bóc tách TOC (H2–H4) từ nội dung bài viết (HTML hoặc Markdown)
 * và lưu vào bảng phẳng `seo_article_headings`.
 */
class ArticleTocExtractionService
{
    private const MIN_LEVEL = 2;

    private const MAX_LEVEL = 4;

    /**
     * Bóc tách heading cho 1 bài viết. Trả về số heading đã lưu.
     */
    public function extractForArticle(SeoArticle $article): int
    {
        $content = $this->resolveArticleContent($article);

        return $this->extractAndStore((int) $article->id, $content);
    }

    /**
     * Xóa heading cũ của article rồi insert lại từ nội dung mới.
     */
    public function extractAndStore(int $articleId, string $content): int
    {
        $headings = $this->parseHeadings($content);

        DB::connection('omi_seo_ai')->transaction(function () use ($articleId, $headings): void {
            SeoArticleHeading::query()->where('article_id', $articleId)->delete();

            /** @var array<int, int> $lastIdByLevel level => id heading gần nhất */
            $lastIdByLevel = [];

            foreach ($headings as $sortOrder => $heading) {
                $level = $heading['level'];
                $parentId = $this->resolveParentId($level, $lastIdByLevel);

                $record = SeoArticleHeading::query()->create([
                    'article_id' => $articleId,
                    'heading_text' => $heading['text'],
                    'heading_slug' => Str::slug($heading['text']),
                    'level' => $level,
                    'sort_order' => $sortOrder,
                    'parent_id' => $parentId,
                ]);

                $lastIdByLevel[$level] = (int) $record->id;

                // Reset các level sâu hơn để H4 không bám nhầm H3 của section trước.
                foreach (range($level + 1, self::MAX_LEVEL) as $deeper) {
                    unset($lastIdByLevel[$deeper]);
                }
            }
        });

        return count($headings);
    }

    /**
     * Nội dung ưu tiên `body`; bài đã đồng bộ WP fallback meta `wp_post_content`.
     */
    public function resolveArticleContent(SeoArticle $article): string
    {
        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return $body;
        }

        return trim((string) (
            $article->articleMetas()
                ->where('meta_key', 'wp_post_content')
                ->value('meta_value') ?? ''
        ));
    }

    /**
     * @return list<array{level: int, text: string}>
     */
    public function parseHeadings(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        if (preg_match('/<h[2-4][\s>]/i', $content) === 1) {
            return $this->parseHtmlHeadings($content);
        }

        return $this->parseMarkdownHeadings($content);
    }

    /**
     * @return list<array{level: int, text: string}>
     */
    private function parseHtmlHeadings(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div>'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//h2 | //h3 | //h4');
        if ($nodes === false) {
            return [];
        }

        $headings = [];
        foreach ($nodes as $node) {
            $text = $this->normalizeHeadingText($node->textContent);
            if ($text === '') {
                continue;
            }

            $headings[] = [
                'level' => (int) substr(strtolower($node->nodeName), 1),
                'text' => $text,
            ];
        }

        return $headings;
    }

    /**
     * @return list<array{level: int, text: string}>
     */
    private function parseMarkdownHeadings(string $markdown): array
    {
        $headings = [];

        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            if (preg_match('/^(#{2,4})\s+(.+?)\s*#*\s*$/', trim($line), $matches) !== 1) {
                continue;
            }

            $text = $this->normalizeHeadingText($matches[2]);
            if ($text === '') {
                continue;
            }

            $headings[] = [
                'level' => strlen($matches[1]),
                'text' => $text,
            ];
        }

        return $headings;
    }

    /**
     * @param  array<int, int>  $lastIdByLevel
     */
    private function resolveParentId(int $level, array $lastIdByLevel): ?int
    {
        if ($level <= self::MIN_LEVEL) {
            return null;
        }

        // Parent là heading gần nhất có level nông hơn (H3 -> H2, H4 -> H3 hoặc H2).
        for ($parentLevel = $level - 1; $parentLevel >= self::MIN_LEVEL; $parentLevel--) {
            if (isset($lastIdByLevel[$parentLevel])) {
                return $lastIdByLevel[$parentLevel];
            }
        }

        return null;
    }

    private function normalizeHeadingText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::limit(trim($text), 255, '');
    }
}
