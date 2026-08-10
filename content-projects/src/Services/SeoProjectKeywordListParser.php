<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

final class SeoProjectKeywordListParser
{
    /**
     * Tách từ khóa theo dòng (và hỗ trợ dấu đầu dòng bullet / số thứ tự).
     *
     * @return list<string>
     */
    public function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^[-*•]\s+/u', '', $line) ?? $line;
            $line = preg_replace('/^\d+[\.\):\-]\s*/u', '', $line) ?? $line;
            $line = trim($line, " \t\"'");

            if ($line !== '') {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<array{type?: string, site_id?: int|null, source_content?: string, description?: string|null}>  $existing
     * @param  list<string>  $keywords
     * @return list<array{type: string, site_id: null, source_content: string, description: null}>
     */
    public function appendKeywordsToTasks(array $existing, array $keywords, string $defaultType = 'create'): array
    {
        foreach ($keywords as $keyword) {
            $phrase = trim($keyword);
            if ($phrase === '') {
                continue;
            }

            $type = SeoProjectTask::normalizeType($defaultType);

            $existing[] = [
                'site_id' => null,
                'type' => $type,
                'source_content' => $phrase,
                'keyword' => $phrase,
                'title' => null,
                'secondary_description' => null,
                'description' => null,
                'post_type' => SeoProjectTask::isNewArticleType($type)
                    ? SeoProjectTask::POST_TYPE_ARTICLE
                    : null,
            ];
        }

        return $existing;
    }
}
