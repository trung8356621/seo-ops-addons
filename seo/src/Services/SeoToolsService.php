<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
final class SeoToolsService
{
    public function __construct(
        private readonly ArticleMarkdownToHtmlService $markdownHtml,
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleContentFaqService $contentFaq,
        private readonly SeoTextTranslateToolService $translateTool,
    ) {}

    public function markdownToHtml(string $markdown): string
    {
        return $this->markdownHtml->convert($markdown);
    }

    public function htmlToMarkdown(string $html): string
    {
        return trim($this->workflowParser->convertHtmlFragmentToMarkdown($html));
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function markdownToFaq(string $markdown): array
    {
        $import = $this->contentFaq->convertMarkdownImport($markdown);

        return is_array($import['faqs'] ?? null) ? $import['faqs'] : [];
    }

    public function translateText(string $input, string $languageSlug): string
    {
        return $this->translateTool->translate($input, $languageSlug);
    }
}
