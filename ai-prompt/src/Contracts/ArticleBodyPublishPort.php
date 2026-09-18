<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Contracts;

use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Canonical article body publish + hash verification for writing persist gate.
 */
interface ArticleBodyPublishPort
{
    /**
     * @return array{
     *     markdown: string,
     *     html: string,
     *     content_hash: string,
     *     faqs: list<array<string, mixed>>,
     *     meta_description: ?string,
     *     h1_title: string
     * }
     */
    public function prepareArticleContent(SeoArticle $article, string $aiOutput): array;

    public function contentHash(string $body): string;

    /**
     * @param  array<string, mixed>  $variables
     * @return array{
     *     success: bool,
     *     message: string,
     *     expected_content_hash?: string,
     *     persisted_content_hash?: string,
     *     body_length?: int,
     *     conflict?: bool
     * }
     */
    public function publishArticle(SeoArticle $article, string $aiOutput, array $variables = []): array;
}
