<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;

final class ArticleMarkdownToHtmlService
{
    public function __construct(
        private readonly SimpleMarkdownHtmlConverter $converter,
    ) {}

    public function convert(string $markdown): string
    {
        return $this->toHtml($markdown);
    }

    public function toHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        return $this->converter->toHtml($markdown);
    }

    /**
     * @return array{
     *     markdown: string,
     *     h1_title: string|null,
     *     meta_description: string|null,
     *     seo_title?: string|null
     * }
     */
    public function prepareImport(string $markdown): array
    {
        return $this->converter->prepareImport($markdown);
    }

    /**
     * Legacy only — không gọi trong production import/convert path.
     */
    public function promoteOrphanH3HeadingsToH2(string $markdown): string
    {
        return $this->converter->promoteOrphanH3HeadingsToH2($markdown);
    }

    public function toFeaturedSnippetEditorHtml(string $markdown): string
    {
        return $this->converter->toFeaturedSnippetEditorHtml($markdown);
    }

    /**
     * @return array{html: string, meta_description: string|null}
     */
    public function convertWithMetadata(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return ['html' => '', 'meta_description' => null];
        }

        return $this->converter->toHtmlWithMetadata($markdown);
    }

    /**
     * @return array{html: string, meta_description: string|null}
     */
    public function stripMetaDescriptionFromHtml(string $html): array
    {
        return $this->converter->stripMetaDescriptionFromHtml($html);
    }
}
