<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use InvalidArgumentException;

/**
 * Canonical Content Project item identity:
 * filled(keyword) || filled(post_title).
 *
 * Shared by Filament, sync normalizer, Command Bus, MCP/Agent, and generation guards.
 */
final class ContentProjectItemIdentity
{
    public static function normalize(?string $value): string
    {
        return trim((string) $value);
    }

    public static function isValid(?string $keyword, ?string $postTitle): bool
    {
        return self::normalize($keyword) !== '' || self::normalize($postTitle) !== '';
    }

    /**
     * Canonical generation subject / title seed:
     * explicit title wins; otherwise keyword.
     *
     * Generation input only — never persist as if user entered project item title.
     */
    public static function effectiveSubject(?string $postTitle, ?string $keyword): string
    {
        $title = self::normalize($postTitle);
        if ($title !== '') {
            return $title;
        }

        return self::normalize($keyword);
    }

    /**
     * Alias of effectiveSubject (prompt topic assembly).
     */
    public static function topic(?string $postTitle, ?string $keyword): string
    {
        return self::effectiveSubject($postTitle, $keyword);
    }

    /**
     * Runtime generation variables from item identity.
     * Does not mutate SeoProjectTask; callers must not persist post_title seed as task.title.
     *
     * @return array{
     *     keyword?: string,
     *     focus_keyword?: string,
     *     topic?: string,
     *     post_title?: string,
     *     title?: string
     * }
     */
    public static function generationSubjectVariables(?string $explicitTitle, ?string $keyword): array
    {
        $keywordNorm = self::normalize($keyword);
        $explicit = self::normalize($explicitTitle);
        $effective = self::effectiveSubject($explicit, $keywordNorm);

        $variables = [];
        if ($keywordNorm !== '') {
            $variables['keyword'] = $keywordNorm;
            $variables['focus_keyword'] = $keywordNorm;
        }
        if ($effective !== '') {
            $variables['topic'] = $effective;
            $variables['post_title'] = $effective;
            $variables['title'] = $effective;
        }

        return $variables;
    }

    public static function failureMessage(): string
    {
        try {
            $translated = (string) __('seo-content-ai::filament.projects.keyword_or_title_required');
            if (
                $translated !== ''
                && $translated !== 'seo-content-ai::filament.projects.keyword_or_title_required'
            ) {
                return $translated;
            }
        } catch (\Throwable) {
            // Pure PHPUnit / no translator.
        }

        return 'Vui lòng nhập ít nhất Từ khóa hoặc Tiêu đề.';
    }

    public static function assertValid(?string $keyword, ?string $postTitle): void
    {
        if (! self::isValid($keyword, $postTitle)) {
            throw new InvalidArgumentException(self::failureMessage());
        }
    }
}
