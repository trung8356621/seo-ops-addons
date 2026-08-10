<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use App\Models\WpOption;

/**
 * Debug tách FAQ theo bài — lưu DB Laravel (bảng wp_options, connection mặc định / omi_channel).
 * Không ghi vào wp_options trên site WordPress.
 */
final class ArticleFaqExtractDebugService
{
    public const OPTION_PREFIX = 'seo_faq_extract_debug_';

    public const SUPPRESSED_PREFIX = 'seo_faq_extract_debug_suppressed_';

    /** @deprecated Chỉ đọc một lần để migrate sang wp_options Laravel */
    private const LEGACY_ARTICLE_META_KEY = 'seo_faq_extract_debug';

    /**
     * @param  array<string, mixed>  $debug
     */
    public function persist(SeoArticle $article, array $debug): void
    {
        $debug['article_id'] = (int) $article->id;
        $debug['updated_at'] = now()->toIso8601String();

        WpOption::set($this->optionName($article), $debug);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(SeoArticle $article): ?array
    {
        if ($this->isSuppressed($article)) {
            return null;
        }

        $data = WpOption::get($this->optionName($article), null);
        if (is_array($data)) {
            return $data;
        }

        return $this->migrateLegacyArticleMetaDebug($article);
    }

    public function isSuppressed(SeoArticle $article): bool
    {
        $flag = WpOption::get($this->suppressedOptionName($article), null);

        return $flag !== null && $flag !== '';
    }

    /**
     * Xóa debug và không tự ghi lại khi mở bài / đồng bộ (nút «Đã fix»).
     */
    public function dismiss(SeoArticle $article): void
    {
        $this->clear($article);

        WpOption::set($this->suppressedOptionName($article), [
            'dismissed_at' => now()->toIso8601String(),
        ]);
    }

    public function clear(SeoArticle $article): void
    {
        WpOption::query()
            ->where('option_name', $this->optionName($article))
            ->delete();

        $article->articleMetas()
            ->where('meta_key', self::LEGACY_ARTICLE_META_KEY)
            ->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function migrateLegacyArticleMetaDebug(SeoArticle $article): ?array
    {
        if ($this->isSuppressed($article)) {
            return null;
        }

        $raw = $article->articleMetas()
            ->where('meta_key', self::LEGACY_ARTICLE_META_KEY)
            ->value('meta_value');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $this->persist($article, $decoded);
        $article->articleMetas()
            ->where('meta_key', self::LEGACY_ARTICLE_META_KEY)
            ->delete();

        return $decoded;
    }

    /**
     * Ghi debug khi có tiêu đề FAQ hoặc câu hỏi nhận diện nhưng không có cặp Q/A.
     *
     * @return array<string, mixed>|null
     */
    public function recordFromContentDiagnosis(
        SeoArticle $article,
        array $diagnosis,
        string $reason,
        string $context = 'article',
    ): ?array {
        if ($this->isSuppressed($article)) {
            return null;
        }

        /** @var array<string, mixed>|null $heading */
        $heading = $diagnosis['heading'] ?? null;
        $candidates = $diagnosis['question_candidates'] ?? [];
        $hasCandidates = is_array($candidates) && $candidates !== [];
        $hasHeading = is_array($heading) && trim((string) ($heading['text'] ?? '')) !== '';

        if (! $hasHeading && ! $hasCandidates) {
            $this->clear($article);

            return null;
        }

        $debug = [
            'reason' => $reason,
            'context' => $context,
            'heading' => $heading,
            'parsed_total' => (int) ($diagnosis['parsed_total'] ?? 0),
            'valid_pairs' => (int) ($diagnosis['valid_pairs'] ?? 0),
            'question_candidates' => $hasCandidates ? $candidates : [],
            'skipped' => $diagnosis['skipped'] ?? [],
        ];

        $this->persist($article, $debug);

        return $debug;
    }

    private function optionName(SeoArticle $article): string
    {
        return self::OPTION_PREFIX . (int) $article->id;
    }

    private function suppressedOptionName(SeoArticle $article): string
    {
        return self::SUPPRESSED_PREFIX . (int) $article->id;
    }
}
