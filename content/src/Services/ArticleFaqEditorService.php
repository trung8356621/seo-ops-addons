<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoFaq;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\ArticleFaqPromptVariablesService;

final class ArticleFaqEditorService
{
    public function __construct(
        private readonly SeoFaqPersistenceService $faqPersistence,
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly ArticleFaqPromptVariablesService $faqPromptVariables,
    ) {
    }

    /**
     * @return list<array{
     *     id: int,
     *     question: string,
     *     answer: string,
     *     more?: string,
     *     sort_order: int,
     *     duplicate: bool,
     *     duplicate_scope: ?string,
     * }>
     */
    public function payloadForArticle(SeoArticle $article): array
    {
        $article->load(['faqs']);

        $siteId = (int) ($article->site_id ?? 0);

        $items = [];
        foreach ($article->faqs as $faq) {
            $normalized = $this->normalizeQuestion((string) $faq->question);
            $duplicateOnSite = $normalized !== ''
                && $this->existsOnSite($siteId, $normalized, (int) $article->id, (int) $faq->id);

            $items[] = [
                'id' => (int) $faq->id,
                'question' => (string) $faq->question,
                'answer' => (string) $faq->answer,
                'more' => (string) ($faq->more ?? ''),
                'sort_order' => (int) $faq->sort_order,
                'duplicate' => $duplicateOnSite,
                'duplicate_scope' => $duplicateOnSite ? 'site' : null,
            ];
        }

        return $items;
    }

    /**
     * @return array{duplicate: bool, duplicate_scope: ?string}
     */
    public function checkDuplicate(SeoArticle $article, string $question, ?int $faqId = null): array
    {
        $normalized = $this->normalizeQuestion($question);
        if ($normalized === '') {
            return ['duplicate' => false, 'duplicate_scope' => null];
        }

        $siteId = (int) ($article->site_id ?? 0);
        $articleId = (int) $article->id;

        if ($this->existsOnSite($siteId, $normalized, $articleId, $faqId)) {
            return ['duplicate' => true, 'duplicate_scope' => 'site'];
        }

        return ['duplicate' => false, 'duplicate_scope' => null];
    }

    /**
     * @param  list<array{id?: int|null, question?: string, answer?: string, more?: string|null, sort_order?: int}>  $rows
     */
    public function saveFromEditor(SeoArticle $article, array $rows): int
    {
        $faqs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));
            $more = trim((string) ($row['more'] ?? ''));
            $answerPlain = trim(html_entity_decode(strip_tags($answer), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($question === '' || $answerPlain === '') {
                continue;
            }
            $faqs[] = [
                'question' => $question,
                'answer' => $answer,
                'more' => $more !== '' ? $more : null,
            ];
        }

        return $this->faqPersistence->persistForArticle($article, $faqs);
    }

    /**
     * @return array{question: string, answer: string}
     */
    public function renewFaq(SeoArticle $article, string $currentQuestion, string $currentAnswer): array
    {
        $promptId = $this->workflowSettings->getRenewFaqPromptId();
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình Prompt làm mới FAQ. Vào SEO → Tùy chỉnh → Quy trình.',
            );
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException('Prompt làm mới FAQ không tồn tại hoặc đã tắt.');
        }

        $variables = $this->faqPromptVariables->buildForArticle($article, [
            'faq_question' => $currentQuestion,
            'faq_answer' => $currentAnswer,
        ]);

        try {
            $result = $this->promptRunner->run($prompt, $variables);
        } catch (PromptRunException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        $parsed = $this->parseRenewOutput((string) ($result->output_text ?? ''));

        if ($parsed['question'] === '' || $parsed['answer'] === '') {
            throw new \InvalidArgumentException('AI không trả về đủ câu hỏi và câu trả lời. Kiểm tra định dạng prompt (JSON hoặc Markdown H3).');
        }

        return $parsed;
    }

    private function normalizeQuestion(string $question): string
    {
        $question = mb_strtolower(trim($question));

        return preg_replace('/\s+/u', ' ', $question) ?? $question;
    }

    private function existsOnSite(int $siteId, string $normalizedQuestion, int $excludeArticleId, ?int $excludeFaqId): bool
    {
        if ($siteId <= 0 || $normalizedQuestion === '') {
            return false;
        }

        $candidates = SeoFaq::query()
            ->join('articles', 'articles.id', '=', 'seo_faqs.article_id')
            ->where('articles.site_id', $siteId)
            ->where('articles.id', '!=', $excludeArticleId)
            ->whereNull('articles.deleted_at')
            ->when($excludeFaqId !== null, fn ($q) => $q->where('seo_faqs.id', '!=', $excludeFaqId))
            ->select(['seo_faqs.id', 'seo_faqs.question'])
            ->limit(500)
            ->get();

        foreach ($candidates as $row) {
            if ($this->normalizeQuestion((string) $row->question) === $normalizedQuestion) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{question: string, answer: string}
     */
    private function parseRenewOutput(string $output): array
    {
        $output = trim($output);
        if ($output === '') {
            return ['question' => '', 'answer' => ''];
        }

        if (str_starts_with($output, '{')) {
            $decoded = json_decode($output, true);
            if (is_array($decoded)) {
                return [
                    'question' => trim((string) ($decoded['question'] ?? $decoded['q'] ?? '')),
                    'answer' => trim((string) ($decoded['answer'] ?? $decoded['a'] ?? '')),
                ];
            }
        }

        if (preg_match('/^###\s+(.+)$/m', $output, $matches) === 1) {
            $question = trim(str_replace('**', '', $matches[1]));
            $answer = trim((string) preg_replace('/^###\s+.+$/m', '', $output, 1));

            return ['question' => $question, 'answer' => $answer];
        }

        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];
        $question = trim((string) ($lines[0] ?? ''));
        $answer = trim(implode("\n", array_slice($lines, 1)));

        return ['question' => $question, 'answer' => $answer];
    }
}
