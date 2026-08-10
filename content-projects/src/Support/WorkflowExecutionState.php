<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\Content\Models\SeoArticle;

final class WorkflowExecutionState
{
    public ?string $lastPromptOutput = null;

    public ?SeoArticle $article = null;

    /** @var array<string, array<string, string>> */
    public array $nodeOutputs = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    /**
     * @param  list<array<string, mixed>>  $parsed
     */
    public function setParsedOutline(array $parsed): void
    {
        $this->meta['seo_article_outlines'] = $parsed;
    }

    /**
     * @param  array<string, list<string>>  $parsed
     */
    public function setParsedKeywords(array $parsed): void
    {
        $this->meta['seo_article_keywords'] = $parsed;
    }

    /**
     * @param  list<array{question: string, answer: string}>  $parsed
     */
    public function setParsedFaqs(array $parsed): void
    {
        $this->meta['seo_article_faqs'] = $parsed;
    }

    /**
     * @param  array{total_score: int, checklist: array<string, mixed>}  $scoreData
     */
    public function setSeoScoreData(array $scoreData): void
    {
        $this->meta['seo_score_data'] = $scoreData;
    }
}
