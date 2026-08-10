<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoRuleViolationsResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;

final class ArticleContentSeoBonusService
{
    public function __construct(
        private readonly WorkflowParserService $workflowParser,
    ) {}

    /**
     * @return array{
     *     faq_count: int,
     *     total_bonus: int,
     *     items: array{
     *         featured_snippet: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *         faq: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *     },
     * }
     */
    public function resolveForArticle(SeoArticle $article, ?string $content = null): array
    {
        $violations = SeoRuleViolationsResolver::forArticle($article);

        if ($content !== null && trim($content) !== '') {
            $faqs = $this->resolveFaqsForScoring($article, $content);
            $runtime = $this->workflowParser->calculateSeoScoreFromContent($content, $faqs);
            $violations = SeoScoringRulesRegistry::sanitizeViolations(
                array_merge($violations, $runtime['violations'] ?? []),
            );
        }

        return $this->formatFromViolations($violations, $this->countArticleFaqs($article));
    }

    /**
     * @return array{
     *     faq_count: int,
     *     total_bonus: int,
     *     items: array{
     *         featured_snippet: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *         faq: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *     },
     * }
     */
    public function resolveFromContent(SeoArticle $article, string $content): array
    {
        $faqs = $this->resolveFaqsForScoring($article, $content);
        $runtime = $this->workflowParser->calculateSeoScoreFromContent($content, $faqs);
        $violations = SeoScoringRulesRegistry::sanitizeViolations($runtime['violations'] ?? []);

        return $this->formatFromViolations($violations, $this->countArticleFaqs($article));
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function resolveFaqsForScoring(SeoArticle $article, string $content): array
    {
        $article->loadMissing(['faqs', 'articleMetas']);

        $dbFaqs = $article->resolveFaqs();
        if (trim($content) === '') {
            return $dbFaqs;
        }

        $contentFaqs = $this->workflowParser->parseFaqsFromContent($content);

        return count($contentFaqs) > count($dbFaqs) ? $contentFaqs : $dbFaqs;
    }

    /**
     * @param  list<string>  $violations
     * @return array{
     *     faq_count: int,
     *     total_bonus: int,
     *     items: array{
     *         featured_snippet: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *         faq: array{key: string, label: string, points: int, max_points: int, passed: bool, message: string},
     *     },
     * }
     */
    private function formatFromViolations(array $violations, int $faqCount): array
    {
        $messages = SeoScoringRulesRegistry::messagesForLocale();
        $faqPassed = ! in_array(SeoScoringRulesRegistry::KEY_FAQ_MISSING, $violations, true);
        $faqDeduction = SeoScoringRulesRegistry::deductionFor(SeoScoringRulesRegistry::KEY_FAQ_MISSING);

        $snippetKey = $this->resolveFeaturedSnippetViolationKey($violations);
        $snippetPassed = $snippetKey === null;
        $snippetDeduction = $snippetKey !== null ? SeoScoringRulesRegistry::deductionFor($snippetKey) : 0;

        $totalDeduction = ($faqPassed ? 0 : $faqDeduction) + $snippetDeduction;

        return [
            'faq_count' => $faqCount,
            'total_bonus' => max(0, ($faqPassed ? $faqDeduction : 0) + ($snippetPassed ? $snippetDeduction : 0) - $totalDeduction),
            'items' => [
                'featured_snippet' => [
                    'key' => 'featured_snippet',
                    'label' => 'FEATURED SNIPPET',
                    'points' => $snippetPassed ? 0 : $snippetDeduction,
                    'max_points' => SeoScoringRulesRegistry::deductionFor(SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_MISSING),
                    'passed' => $snippetPassed,
                    'message' => $snippetKey !== null
                        ? ($messages['seo_rules.'.$snippetKey] ?? $snippetKey)
                        : __('seo_rules.all_passed'),
                ],
                'faq' => [
                    'key' => 'faq',
                    'label' => 'FAQ',
                    'points' => $faqPassed ? 0 : $faqDeduction,
                    'max_points' => $faqDeduction,
                    'passed' => $faqPassed,
                    'message' => $faqPassed
                        ? __('seo_rules.all_passed')
                        : ($messages['seo_rules.faq_missing'] ?? 'FAQ missing'),
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $violations
     */
    private function resolveFeaturedSnippetViolationKey(array $violations): ?string
    {
        foreach ([
            SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_MISSING,
            SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_GOOD,
            SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_EXCELLENT,
        ] as $key) {
            if (in_array($key, $violations, true)) {
                return $key;
            }
        }

        return null;
    }

    private function countArticleFaqs(SeoArticle $article): int
    {
        $article->loadMissing('faqs');

        return $article->faqs->count();
    }
}
