<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ArticleGenerationInputResolver;

/**
 * Canonical outline meta (tab Dàn ý) = article_meta.seo_article_outline.
 * Persist / hasUsableOutline cho outline tab & dependency check.
 *
 * Outline is article structure for writing/editing/generation.
 * Duplicate-topic detection belongs to Keyword Intelligence / cluster / intent / Content Project planning.
 *
 * Article {{input}} (rewrite / first-run / content retry) → ArticleGenerationInputResolver.
 */
class ArticleOutlineResolver
{
    public const META_KEY = 'seo_article_outline';

    public const META_KEY_PARSED = 'seo_article_outlines';

    public function __construct(
        private readonly WorkflowParserService $workflowParser,
    ) {}

    public function resolveMarkdown(?SeoArticle $article): string
    {
        if (! $article instanceof SeoArticle) {
            return '';
        }

        $article->loadMissing('articleMetas');
        $raw = trim((string) (
            $article->articleMetas->firstWhere('meta_key', self::META_KEY)?->meta_value ?? ''
        ));

        if (! $this->isUsable($raw)) {
            return '';
        }

        return $raw;
    }

    public function hasUsableOutline(?SeoArticle $article): bool
    {
        return $this->resolveMarkdown($article) !== '';
    }

    public function isUsable(string $markdown): bool
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return false;
        }

        $normalized = mb_strtolower($markdown);
        if (in_array($normalized, ['{}', '[]', 'null', 'undefined'], true)) {
            return false;
        }

        if (str_starts_with($markdown, '{') || str_starts_with($markdown, '[')) {
            $decoded = json_decode($markdown, true);
            if (is_array($decoded)) {
                return $this->parsedStructureHasHeadings($decoded);
            }
        }

        $parsed = $this->workflowParser->parseOutline($markdown);
        if ($parsed !== []) {
            return true;
        }

        // Outline plain / H1-only / bullet list — vẫn dùng được nếu có nội dung thật.
        if (preg_match('/^#{1,6}\s+\S+/mu', $markdown) === 1) {
            return mb_strlen($markdown) >= 8;
        }

        if (preg_match('/^[\-\*\d]+[\.\)]\s+\S+/mu', $markdown) === 1) {
            return mb_strlen($markdown) >= 8;
        }

        return mb_strlen($markdown) >= 40;
    }

    /**
     * @return array{ok: bool, markdown: string, message: string|null}
     */
    public function persist(SeoArticle $article, string $markdown): array
    {
        $markdown = trim($markdown);
        if (! $this->isUsable($markdown)) {
            return [
                'ok' => false,
                'markdown' => '',
                'message' => 'Output outline không hợp lệ (rỗng hoặc không có nội dung dùng được).',
            ];
        }

        // Giữ raw markers nếu có — regeneration cần đủ contract 2 section.
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => $markdown],
        );

        $parsed = $this->workflowParser->parseOutline($markdown);
        if ($parsed !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => self::META_KEY_PARSED],
                ['meta_value' => json_encode($parsed, JSON_UNESCAPED_UNICODE)],
            );
        }

        $article->unsetRelation('articleMetas');
        $article->load('articleMetas');

        if (! $this->hasUsableOutline($article)) {
            return [
                'ok' => false,
                'markdown' => $markdown,
                'message' => 'Không lưu được outline vào meta bài viết.',
            ];
        }

        return [
            'ok' => true,
            'markdown' => $markdown,
            'message' => null,
        ];
    }

    /**
     * @param  array<mixed>  $decoded
     */
    private function parsedStructureHasHeadings(array $decoded): bool
    {
        if ($decoded === []) {
            return false;
        }

        foreach ($decoded as $node) {
            if (! is_array($node)) {
                continue;
            }
            $text = trim((string) ($node['text'] ?? $node['title'] ?? ''));
            if ($text !== '') {
                return true;
            }
            $children = $node['children'] ?? null;
            if (is_array($children) && $this->parsedStructureHasHeadings($children)) {
                return true;
            }
        }

        return false;
    }
}
