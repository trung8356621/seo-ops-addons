<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * Strip whole-article metadata wrappers that section children must not contribute.
 * Removes label lines (Meta description / SEO title / H1 wrappers), not deep prose.
 */
final class SectionedFreeChildOutputNormalizer
{
    /**
     * Remove Meta description / SEO title / leading H1 wrappers from a section child output.
     */
    public function stripArticleMetadataWrappers(string $output): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];
        $kept = [];
        $leading = true;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if (! $leading) {
                    $kept[] = $line;
                }

                continue;
            }

            if ($this->isMetadataWrapperLine($trimmed, $leading)) {
                continue;
            }

            $leading = false;
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    /**
     * @return array{meta_description: int, seo_title: int}
     */
    public function countMetadataOccurrences(string $text): array
    {
        return [
            'meta_description' => preg_match_all('/^\s*Meta\s+description\s*:/imu', $text) ?: 0,
            'seo_title' => preg_match_all('/^\s*SEO\s+title\s*:/imu', $text) ?: 0,
        ];
    }

    private function isMetadataWrapperLine(string $trimmed, bool $leading): bool
    {
        if (preg_match('/^Meta\s+description\s*:/iu', $trimmed) === 1) {
            return true;
        }
        if (preg_match('/^SEO\s+title\s*:/iu', $trimmed) === 1) {
            return true;
        }
        if (preg_match('/^(?:H1|Article\s+title)\s*:/iu', $trimmed) === 1) {
            return true;
        }

        $plain = trim((string) preg_replace('/^\*{1,2}|\*{1,2}$/u', '', $trimmed));
        $plain = trim((string) preg_replace('/^#+\s*/u', '', $plain));
        if (in_array(mb_strtolower($plain), [
            'meta description:',
            'meta description',
            'seo title:',
            'seo title',
            'complete article body',
            '[complete article body]',
        ], true)) {
            return true;
        }

        // Standalone H1 markdown line often repeats article title — strip only while still leading.
        if ($leading && preg_match('/^#\s+\S/u', $trimmed) === 1 && preg_match('/^##/u', $trimmed) !== 1) {
            return true;
        }

        return false;
    }
}
