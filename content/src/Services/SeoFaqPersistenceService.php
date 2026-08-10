<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoFaq;
use Illuminate\Support\Str;

final class SeoFaqPersistenceService
{
    /**
     * Thay thế toàn bộ FAQ của bài viết (xóa cũ → bulk insert).
     *
     * @param  list<array{question?: string, answer?: string, more?: string|null}>  $faqs
     */
    public function persistForArticle(SeoArticle $article, array $faqs): int
    {
        $article->faqs()->delete();
        $article->unsetRelation('faqs');

        $rows = $this->buildInsertRows($article->id, $faqs);

        if ($rows === []) {
            $this->removeLegacyMeta($article);

            return 0;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SeoFaq::insert($chunk);
        }

        $this->persistFaqsMetaSnapshot($article, $faqs);
        $this->removeLegacyMeta($article);

        return count($rows);
    }

    /**
     * @param  list<array{question?: string, answer?: string, more?: string|null}>  $faqs
     */
    private function persistFaqsMetaSnapshot(SeoArticle $article, array $faqs): void
    {
        if ($faqs === []) {
            return;
        }

        $payload = array_map(static fn (array $faq): array => [
            'question' => (string) ($faq['question'] ?? ''),
            'answer' => (string) ($faq['answer'] ?? ''),
            'more' => (string) ($faq['more'] ?? ''),
        ], $faqs);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_faqs'],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * @param  list<array{question?: string, answer?: string, more?: string|null}>  $faqs
     * @return list<array<string, mixed>>
     */
    private function buildInsertRows(int $articleId, array $faqs): array
    {
        $rows = [];
        $now = now();
        $sortOrder = 1;

        foreach ($faqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }

            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            $more = trim((string) ($faq['more'] ?? ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            $rows[] = [
                'article_id' => $articleId,
                'question' => Str::limit($question, 500, ''),
                'answer' => $answer,
                'more' => $more !== '' ? $more : null,
                'sort_order' => $sortOrder++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private function removeLegacyMeta(SeoArticle $article): void
    {
        $article->articleMetas()
            ->where('meta_key', 'seo_article_faqs')
            ->delete();
    }
}
