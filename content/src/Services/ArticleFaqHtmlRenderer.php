<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;

final class ArticleFaqHtmlRenderer
{
    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    public function resolveFaqs(SeoArticle $article): array
    {
        return $article->resolveFaqs();
    }

    public function renderAccordionHtml(SeoArticle $article): string
    {
        return $this->renderAccordionFromRows($this->resolveFaqs($article));
    }

    /**
     * @param  list<array{question?: string, answer?: string, more?: string|null}>  $faqs
     */
    public function renderAccordionFromRows(array $faqs): string
    {
        if ($faqs === []) {
            return '';
        }

        $parts = ['<div class="omi-faq-container seo-article-preview-faq">'];
        $index = 0;

        foreach ($faqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }

            $questionRaw = trim((string) ($faq['question'] ?? ''));
            $answerRaw = trim((string) ($faq['answer'] ?? ''));
            $moreRaw = trim((string) ($faq['more'] ?? ''));

            if ($questionRaw === '' || $answerRaw === '') {
                continue;
            }

            $question = htmlspecialchars($this->numberedQuestionLabel($questionRaw, $index), ENT_QUOTES, 'UTF-8');
            $answerHtml = $this->formatFaqHtmlField($answerRaw);
            $moreHtml = $moreRaw !== '' ? $this->formatFaqHtmlField($moreRaw) : '';

            $openAttr = $index === 0 ? ' open' : '';
            $parts[] = '<details class="omi-faq-item"' . $openAttr . '>';
            $parts[] = '<summary class="omi-faq-item__summary">';
            $parts[] = '<span class="omi-faq-item__chevron" aria-hidden="true"></span>';
            $parts[] = '<span class="omi-faq-item__question">' . $question . '</span>';
            $parts[] = '</summary>';
            $parts[] = '<div class="omi-faq-item__body">';
            if ($moreHtml !== '') {
                $parts[] = '<div class="omi-faq-item__more">' . $moreHtml . '</div>';
            }
            $parts[] = '<div class="omi-faq-item__answer">' . $answerHtml . '</div>';
            $parts[] = '</div>';
            $parts[] = '</details>';

            $index++;
        }

        if ($index === 0) {
            return '';
        }

        $parts[] = '</div>';

        return implode("\n", $parts);
    }

    public function renderBodyWithFaqs(SeoArticle $article): string
    {
        $body = trim((string) ($article->body ?? ''));
        $faqHtml = $this->renderAccordionHtml($article);

        if ($body === '') {
            return $faqHtml;
        }

        if ($faqHtml === '') {
            return $body;
        }

        if (str_contains($body, '[omi_faq]')) {
            return str_replace('[omi_faq]', $faqHtml, $body);
        }

        if (preg_match('/<p[^>]*class="[^"]*omi-faq-placeholder[^"]*"[^>]*>\s*\[omi_faq\]\s*<\/p>/i', $body) === 1) {
            return preg_replace(
                '/<p[^>]*class="[^"]*omi-faq-placeholder[^"]*"[^>]*>\s*\[omi_faq\]\s*<\/p>/i',
                $faqHtml,
                $body,
                1,
            ) ?? $body;
        }

        return $body . "\n\n" . $faqHtml;
    }

    private function numberedQuestionLabel(string $question, int $index): string
    {
        if (preg_match('/^\d+[\.\)]\s/u', $question) === 1) {
            return $question;
        }

        return ($index + 1) . '. ' . $question;
    }

    private function formatFaqHtmlField(string $raw): string
    {
        if (preg_match('/<[a-z][\s\S]*>/i', $raw) === 1) {
            return $raw;
        }

        return nl2br(htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'), false);
    }
}
