<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;

/**
 * Article-body word count aligned with Article Editor metrics
 * ({@see resources/js/utils/articleEditorMetrics.js} countWordsFromHtmlLight).
 *
 * Source of truth: saved {@see \Omnichannel\Addons\Content\Models\SeoArticle::$body} only.
 * Never use PromptResult / AI output snapshots / token usage as a substitute.
 */
final class ArticleBodyWordCounter
{
    /**
     * Count words in saved article HTML, or null when there is no countable content.
     * Empty / missing body → null (UI shows "—", not 0).
     */
    public static function countOrNull(?string $html): ?int
    {
        if ($html === null) {
            return null;
        }

        $raw = trim($html);
        if ($raw === '') {
            return null;
        }

        $count = self::count($raw);

        return $count > 0 ? $count : null;
    }

    /**
     * Always returns an int (0 when empty). Prefer {@see countOrNull()} for list UI.
     */
    public static function count(string $html): int
    {
        $plain = self::toPlainText($html);

        return PromptTextMetrics::wordCount($plain);
    }

    /**
     * Display label: "—" when null/empty, else thousands-separated integer (e.g. 3.125).
     */
    public static function format(?int $count): string
    {
        if ($count === null || $count <= 0) {
            return '—';
        }

        return number_format($count, 0, ',', '.');
    }

    /**
     * Mirror editor stripHtmlToText + shortcode tag removal (syntax not counted).
     */
    public static function toPlainText(string $html): string
    {
        $text = $html;
        $text = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $text);
        $text = (string) preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text);
        $text = self::stripShortcodeSyntax($text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) (preg_replace('/\s+/u', ' ', $text) ?? ''));
    }

    /**
     * Remove WP shortcode tag syntax; keep enclosed inner text when present.
     * Escaped [[literal]] stays as [literal].
     */
    private static function stripShortcodeSyntax(string $content): string
    {
        if ($content === '' || ! str_contains($content, '[')) {
            return $content;
        }

        /** @var array<string, string> $escaped */
        $escaped = [];
        $content = (string) preg_replace_callback(
            '/\[\[([^\]]+)\]\]/',
            static function (array $matches) use (&$escaped): string {
                $token = '___WP_SC_ESC_'.count($escaped).'___';
                $escaped[$token] = '['.$matches[1].']';

                return $token;
            },
            $content,
        );

        $iterations = 0;
        do {
            $before = $content;
            // Keep surrounding spaces so adjacent shortcode tags do not glue words.
            $content = (string) preg_replace(
                '/\[(?!\/)([a-z0-9_-]+)(?:[^\]]*)?\](.*?)\[\/\1\]/is',
                ' $2 ',
                $content,
            );
            $content = (string) preg_replace(
                '/\[(?:\/[a-z0-9_-]+|[a-z0-9_-]+)(?:[^\]]*)?\]/i',
                ' ',
                $content,
            );
            $iterations++;
        } while ($content !== $before && $iterations < 50);

        foreach ($escaped as $token => $literal) {
            $content = str_replace($token, $literal, $content);
        }

        return $content;
    }
}
